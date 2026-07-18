<?php

namespace Tests;

use App\Services\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests App\Services\SessionManager against in-memory SQLite. This backs the
 * DB session store + active-session management (validate, terminate, terminate-all),
 * so its expiry and isolation semantics are security-relevant.
 */
class SessionManagerTest extends TestCase
{
    private \PDO $db;
    private SessionManager $sm;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->db->exec("CREATE TABLE user_sessions (
            id VARCHAR(128) PRIMARY KEY,
            user_id INTEGER,
            data TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            expires_at TIMESTAMP,
            last_active TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
        $this->sm = new SessionManager($this->db);
    }

    public function testTrackThenValidate(): void
    {
        $this->sm->trackSession(42, 'sid-A', '203.0.113.5', 'Mozilla/5.0 Test');
        $this->assertTrue($this->sm->validateSession('sid-A'));
    }

    public function testUnknownSessionIsInvalid(): void
    {
        $this->assertFalse($this->sm->validateSession('nope'));
    }

    public function testExpiredSessionIsInvalid(): void
    {
        // Row exists but already expired → validateSession must reject it.
        $this->db->prepare("INSERT INTO user_sessions (id, user_id, ip_address, user_agent, expires_at, last_active)
            VALUES ('sid-old', 42, '203.0.113.5', 'Mozilla/5.0 Test', datetime('now','-1 hour'), datetime('now','-2 hour'))")
            ->execute();
        $this->assertFalse($this->sm->validateSession('sid-old'));
    }

    public function testTerminateSessionRemovesIt(): void
    {
        $this->sm->trackSession(42, 'sid-A', '203.0.113.5', 'Mozilla/5.0 Test');
        $this->assertTrue($this->sm->terminateSession('sid-A'));
        $this->assertFalse($this->sm->validateSession('sid-A'));
        // Terminating a non-existent session reports false.
        $this->assertFalse($this->sm->terminateSession('sid-A'));
    }

    public function testTerminateAllExceptKeepsCurrent(): void
    {
        $this->sm->trackSession(42, 'sid-A', '203.0.113.5', 'Mozilla/5.0 Test');
        $this->sm->trackSession(42, 'sid-B', '203.0.113.5', 'Mozilla/5.0 Test');
        $this->sm->trackSession(42, 'sid-C', '203.0.113.5', 'Mozilla/5.0 Test');
        // Another user's session must never be touched.
        $this->sm->trackSession(99, 'sid-other', '203.0.113.5', 'Mozilla/5.0 Test');

        $removed = $this->sm->terminateAllSessions(42, 'sid-A');
        $this->assertSame(2, $removed);
        $this->assertTrue($this->sm->validateSession('sid-A'));
        $this->assertFalse($this->sm->validateSession('sid-B'));
        $this->assertFalse($this->sm->validateSession('sid-C'));
        $this->assertTrue($this->sm->validateSession('sid-other'), "other user's session untouched");
    }

    public function testGetActiveSessionsListsUserRows(): void
    {
        $this->sm->trackSession(42, 'sid-A', '203.0.113.5', 'Mozilla/5.0 Test');
        $this->sm->trackSession(42, 'sid-B', '203.0.113.5', 'Mozilla/5.0 Test');
        $this->sm->trackSession(99, 'sid-other', '203.0.113.5', 'Mozilla/5.0 Test');

        $rows = $this->sm->getActiveSessions(42);
        $ids = array_column($rows, 'id');
        sort($ids);
        $this->assertSame(['sid-A', 'sid-B'], $ids);
        $this->assertArrayHasKey('is_current', $rows[0]);
    }
}
