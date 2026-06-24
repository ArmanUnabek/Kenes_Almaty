<?php

namespace Tests;

use App\Services\TelegramService;
use Tests\Support\RepositoryTestCase;

/**
 * Unit-tests the non-network surface of App\Services\TelegramService:
 * isConfigured() (token presence) and findUserByChatId() (DB lookup against
 * in-memory SQLite). The HTTP send* methods are out of scope — they require a
 * live Telegram API and would need transport injection to test.
 */
class TelegramServiceTest extends RepositoryTestCase
{
    public function testIsConfiguredFalseWithoutToken(): void
    {
        // config.php (loaded by bootstrap) always define()s TELEGRAM_BOT_TOKEN — to
        // '' when unset — and botToken() prefers the constant over getenv(), so in
        // the test environment (no token) isConfigured() must be false.
        $this->assertFalse(TelegramService::isConfigured());
    }

    public function testFindUserByChatIdReturnsActiveUser(): void
    {
        $this->db->exec("INSERT INTO users (id, username, full_name, role, region_id, is_active, telegram_chat_id)
            VALUES (1, 'ivanov', 'Иванов И.', 'editor', 2, 1, '555000')");

        $user = TelegramService::findUserByChatId($this->db, '555000');
        $this->assertNotNull($user);
        $this->assertSame('ivanov', $user['username']);
        $this->assertSame('Иванов И.', $user['full_name']);
        $this->assertSame('2', (string)$user['region_id']);
    }

    public function testFindUserByChatIdIgnoresInactiveUser(): void
    {
        $this->db->exec("INSERT INTO users (id, username, full_name, is_active, telegram_chat_id)
            VALUES (2, 'disabled', 'X', 0, '999')");
        $this->assertNull(TelegramService::findUserByChatId($this->db, '999'));
    }

    public function testFindUserByChatIdUnknownChat(): void
    {
        $this->assertNull(TelegramService::findUserByChatId($this->db, '404'));
    }

    public function testFindUserByChatIdEmptyStringShortCircuits(): void
    {
        $this->assertNull(TelegramService::findUserByChatId($this->db, ''));
    }
}
