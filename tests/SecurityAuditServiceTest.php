<?php

namespace Tests;

use App\Services\SecurityAuditService;
use Tests\Support\RepositoryTestCase;

/**
 * Unit-tests App\Services\SecurityAuditService against in-memory SQLite. Verifies
 * the audit row is written with the correct columns, that request metadata
 * ($_SERVER ip/user-agent) is captured into the JSON details, and that the
 * higher-level logExport/logScanDownload helpers build the expected payloads.
 */
class SecurityAuditServiceTest extends RepositoryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->exec("CREATE TABLE audit_logs (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            region_id INTEGER,
            table_name TEXT,
            operation TEXT,
            record_id INTEGER,
            old_values TEXT,
            new_values TEXT,
            ip_address TEXT,
            user_agent TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/Test';
    }

    private function lastRow(): array
    {
        return $this->db->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1')
            ->fetch(\PDO::FETCH_ASSOC);
    }

    public function testLogWritesRowWithMetadataAndDetails(): void
    {
        SecurityAuditService::log($this->db, 'update', 'os_members', 42, ['field' => 'name'], 5, 1);

        $row = $this->lastRow();
        $this->assertSame('os_members', $row['table_name']);
        $this->assertSame('UPDATE', $row['operation'], 'operation is upper-cased');
        $this->assertSame('42', (string)$row['record_id']);
        $this->assertSame('5', (string)$row['user_id']);
        $this->assertSame('1', (string)$row['region_id']);
        $this->assertSame('203.0.113.7', $row['ip_address']);
        $this->assertSame('PHPUnit/Test', $row['user_agent']);

        $details = json_decode($row['new_values'], true);
        $this->assertSame('name', $details['field']);
        $this->assertSame('203.0.113.7', $details['ip'], 'ip merged into details');
        $this->assertSame('PHPUnit/Test', $details['user_agent']);
    }

    public function testLogToleratesNullDetails(): void
    {
        SecurityAuditService::log($this->db, 'delete', 'commissions', 9, null, null, null);
        $row = $this->lastRow();
        $details = json_decode($row['new_values'], true);
        $this->assertIsArray($details);
        $this->assertArrayHasKey('ip', $details);
    }

    public function testLogExportComputesHighVolumeFlag(): void
    {
        SecurityAuditService::logExport($this->db, 5, 2, 'csv', 30, 25); // total 55 >= 50
        $details = json_decode($this->lastRow()['new_values'], true);
        $this->assertSame('csv', $details['format']);
        $this->assertSame(55, $details['total_records']);
        $this->assertTrue($details['high_volume']);
    }

    public function testLogExportLowVolumeFlag(): void
    {
        SecurityAuditService::logExport($this->db, 5, 2, 'json', 1, 1); // total 2 < 50
        $details = json_decode($this->lastRow()['new_values'], true);
        $this->assertFalse($details['high_volume']);
    }

    public function testLogScanDownloadBuildsDetails(): void
    {
        SecurityAuditService::logScanDownload($this->db, 5, 1, 77, 'incoming', 12, 'scan.pdf');
        $row = $this->lastRow();
        $this->assertSame('DOWNLOAD', $row['operation']);
        $this->assertSame('letter_scans', $row['table_name']);
        $this->assertSame('77', (string)$row['record_id']);
        $details = json_decode($row['new_values'], true);
        $this->assertSame('incoming', $details['letter_type']);
        $this->assertSame(12, $details['letter_id']);
        $this->assertSame('scan.pdf', $details['file_name']);
    }
}
