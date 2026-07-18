<?php

namespace Tests;

use App\Services\ScanVersionService;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests the scan version-chain logic of App\Services\ScanVersionService
 * against in-memory SQLite: current version = the row with replaced_by IS NULL,
 * and restore creates a new head that supersedes the previous one. The
 * upload path (which needs LetterService::prepareScanPayload / FileStorage) is
 * out of scope here; rows are seeded directly to exercise the pointer logic.
 */
class ScanVersionServiceTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)");
        $this->db->exec("INSERT INTO users (id, full_name) VALUES (7, 'Тест Юзер')");
        $this->db->exec("CREATE TABLE letter_scans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            letter_type TEXT NOT NULL,
            letter_id INTEGER NOT NULL,
            file_path TEXT, scan_data TEXT, scan_type TEXT, file_name TEXT, file_size INTEGER,
            version INTEGER NOT NULL DEFAULT 1,
            parent_scan_id INTEGER,
            replaced_by INTEGER,
            replaced_at TEXT,
            uploaded_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    }

    private function seedScan(int $version, ?int $replacedBy, ?int $parent = null): int
    {
        $this->db->prepare("INSERT INTO letter_scans
            (letter_type, letter_id, file_path, scan_type, file_name, version, parent_scan_id, replaced_by, uploaded_by)
            VALUES ('incoming', 5, ?, 'application/pdf', ?, ?, ?, ?, 7)")
            ->execute(["/uploads/v{$version}.pdf", "v{$version}.pdf", $version, $parent, $replacedBy]);
        return (int)$this->db->lastInsertId();
    }

    public function testCurrentVersionIsTheUnreplacedHead(): void
    {
        $v1 = $this->seedScan(1, /*replaced later*/ null);
        $v2 = $this->seedScan(2, null, $v1);
        // v1 is replaced by v2
        $this->db->prepare('UPDATE letter_scans SET replaced_by = ? WHERE id = ?')->execute([$v2, $v1]);

        $current = ScanVersionService::getCurrentVersion($this->db, 'incoming', 5);
        $this->assertNotNull($current);
        $this->assertSame($v2, (int)$current['id']);
        $this->assertSame(2, (int)$current['version']);
        $this->assertSame('Тест Юзер', $current['uploaded_by_name']);
    }

    public function testGetVersionsReturnsFullHistoryNewestFirst(): void
    {
        $v1 = $this->seedScan(1, null);
        $v2 = $this->seedScan(2, null, $v1);
        $this->db->prepare('UPDATE letter_scans SET replaced_by = ? WHERE id = ?')->execute([$v2, $v1]);

        $versions = ScanVersionService::getVersions($this->db, 'incoming', 5);
        $this->assertCount(2, $versions);
        $this->assertSame(2, (int)$versions[0]['version']);
        $this->assertSame(1, (int)$versions[1]['version']);
    }

    public function testNoScansReturnsNullCurrentAndEmptyHistory(): void
    {
        $this->assertNull(ScanVersionService::getCurrentVersion($this->db, 'incoming', 5));
        $this->assertSame([], ScanVersionService::getVersions($this->db, 'incoming', 5));
    }

    public function testRestoreOldVersionCreatesNewHeadAndSupersedesPrevious(): void
    {
        $v1 = $this->seedScan(1, null);
        $v2 = $this->seedScan(2, null, $v1);
        $this->db->prepare('UPDATE letter_scans SET replaced_by = ? WHERE id = ?')->execute([$v2, $v1]);

        $restored = ScanVersionService::restoreVersion($this->db, $v1, 7);

        // A brand-new head (version 3) is created from v1's payload.
        $this->assertSame(3, (int)$restored['version']);
        $this->assertSame('v1.pdf', $restored['file_name']);

        $current = ScanVersionService::getCurrentVersion($this->db, 'incoming', 5);
        $this->assertSame((int)$restored['id'], (int)$current['id']);

        // The previous head (v2) is now replaced; total history is 3 rows.
        $this->assertCount(3, ScanVersionService::getVersions($this->db, 'incoming', 5));
    }

    public function testRestoringTheCurrentVersionIsANoop(): void
    {
        $v1 = $this->seedScan(1, null);
        $before = ScanVersionService::getVersions($this->db, 'incoming', 5);

        $result = ScanVersionService::restoreVersion($this->db, $v1, 7);

        $this->assertSame($v1, (int)$result['id']);
        // No new row created.
        $this->assertCount(count($before), ScanVersionService::getVersions($this->db, 'incoming', 5));
    }

    public function testRestoreMissingScanThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        ScanVersionService::restoreVersion($this->db, 9999, 7);
    }
}
