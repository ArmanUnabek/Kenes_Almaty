<?php

namespace Tests;

use App\Services\ApiKeyService;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests App\Services\ApiKeyService against in-memory SQLite: key creation
 * (hash stored, raw returned once), validation (prefix guard, active/expiry
 * checks, hash match), revocation scoping, and masking. This is a REST auth path,
 * so the reject branches matter.
 */
class ApiKeyServiceTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY, username TEXT, full_name TEXT, email TEXT,
            role TEXT DEFAULT 'viewer', region_id INTEGER, is_active INTEGER DEFAULT 1
        )");
        $this->db->exec("INSERT INTO users (id, username, full_name, role, is_active) VALUES (1, 'admin', 'Админ', 'admin', 1)");
        $this->db->exec("CREATE TABLE api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL, name TEXT, key_hash TEXT,
            permissions TEXT, is_active INTEGER DEFAULT 1,
            last_used_at TIMESTAMP, expires_at TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function testCreateReturnsRawKeyOnceAndStoresOnlyHash(): void
    {
        $res = ApiKeyService::createKey($this->db, 1, 'CI token', ['read']);
        $this->assertStringStartsWith('ak_', $res['key']);
        $stored = $this->db->query('SELECT key_hash FROM api_keys')->fetchColumn();
        $this->assertSame(hash('sha256', $res['key']), $stored, 'only the hash is persisted');
        $this->assertNotSame($res['key'], $stored, 'raw key is never stored');
    }

    public function testValidateReturnsUserForGoodKey(): void
    {
        $res = ApiKeyService::createKey($this->db, 1, 'CI token', ['read', 'write']);
        $user = ApiKeyService::validateKey($this->db, $res['key']);
        $this->assertNotNull($user);
        $this->assertSame(1, (int)$user['id']);
        $this->assertSame('admin', $user['role']);
        $this->assertSame(['read', 'write'], $user['api_key_permissions']);
        // last_used_at is stamped (driver-aware now).
        $this->assertNotNull($this->db->query('SELECT last_used_at FROM api_keys')->fetchColumn());
    }

    public function testValidateRejectsWrongPrefixWithoutQuery(): void
    {
        $this->assertNull(ApiKeyService::validateKey($this->db, 'nope_123'));
    }

    public function testValidateRejectsUnknownKey(): void
    {
        $this->assertNull(ApiKeyService::validateKey($this->db, 'ak_' . str_repeat('0', 96)));
    }

    public function testValidateRejectsExpiredKey(): void
    {
        $res = ApiKeyService::createKey($this->db, 1, 'expired');
        $this->db->exec("UPDATE api_keys SET expires_at = datetime('now','-1 hour')");
        $this->assertNull(ApiKeyService::validateKey($this->db, $res['key']));
    }

    public function testValidateRejectsRevokedKeyAndInactiveUser(): void
    {
        $res = ApiKeyService::createKey($this->db, 1, 'k');
        $this->db->exec("UPDATE api_keys SET is_active = 0");
        $this->assertNull(ApiKeyService::validateKey($this->db, $res['key']));

        $this->db->exec("UPDATE api_keys SET is_active = 1");
        $this->db->exec("UPDATE users SET is_active = 0 WHERE id = 1");
        $this->assertNull(ApiKeyService::validateKey($this->db, $res['key']), 'inactive user cannot auth');
    }

    public function testRevokeIsScopedToOwner(): void
    {
        $res = ApiKeyService::createKey($this->db, 1, 'k');
        $id = $res['id'];
        // Wrong owner cannot revoke.
        $this->assertFalse(ApiKeyService::revokeKey($this->db, $id, 999));
        $this->assertNotNull(ApiKeyService::validateKey($this->db, $res['key']));
        // Correct owner revokes.
        $this->assertTrue(ApiKeyService::revokeKey($this->db, $id, 1));
        $this->assertNull(ApiKeyService::validateKey($this->db, $res['key']));
    }

    public function testMaskKeyHidesMiddle(): void
    {
        $this->assertSame('ak_abc...wxyz', ApiKeyService::maskKey('ak_abcDEFGHwxyz'));
        $this->assertSame('short', ApiKeyService::maskKey('short'));
    }
}
