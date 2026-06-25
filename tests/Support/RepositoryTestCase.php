<?php

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base class for repository tests that run real SQL against an in-memory SQLite
 * database. This exercises the actual queries (and region-scoping WHERE clauses)
 * instead of mocking PDO, which is where data-isolation bugs would otherwise hide.
 *
 * The schema below is a minimal SQLite-compatible subset of the production tables
 * covering only the columns the repositories touch.
 */
abstract class RepositoryTestCase extends TestCase
{
    protected \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->createSchema();
    }

    private function createSchema(): void
    {
        $this->db->exec("CREATE TABLE regions (
            id INTEGER PRIMARY KEY,
            name_ru TEXT,
            name_kz TEXT,
            code TEXT,
            is_active INTEGER DEFAULT 1
        )");

        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            email TEXT,
            password_hash TEXT,
            full_name TEXT,
            role TEXT DEFAULT 'viewer',
            region_id INTEGER,
            is_active INTEGER DEFAULT 1,
            last_login TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            telegram_chat_id TEXT
        )");

        $this->db->exec("CREATE TABLE commissions (
            id INTEGER PRIMARY KEY,
            region_id INTEGER,
            name TEXT,
            color TEXT DEFAULT '#6c757d',
            sort_order INTEGER DEFAULT 0
        )");

        $this->db->exec("CREATE TABLE os_members (
            id INTEGER PRIMARY KEY,
            region_id INTEGER,
            full_name TEXT,
            position TEXT,
            position_kz TEXT,
            organization TEXT,
            organization_kz TEXT,
            commission_id INTEGER,
            email TEXT,
            phone TEXT,
            birth_date TEXT,
            facebook TEXT,
            whatsapp TEXT,
            instagram TEXT,
            status TEXT DEFAULT 'active',
            photo_path TEXT
        )");
    }

    protected function seedRegion(int $id, string $nameRu = 'Регион'): void
    {
        $stmt = $this->db->prepare('INSERT INTO regions (id, name_ru, code) VALUES (?, ?, ?)');
        $stmt->execute([$id, $nameRu, 'R' . $id]);
    }

    protected function seedCommission(int $id, int $regionId, string $name, int $sort = 0): void
    {
        $stmt = $this->db->prepare('INSERT INTO commissions (id, region_id, name, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->execute([$id, $regionId, $name, $sort]);
    }
}
