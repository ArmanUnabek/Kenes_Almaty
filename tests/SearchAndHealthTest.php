<?php

namespace Tests;

use App\Services\HealthReport;
use App\Services\SearchRanker;
use PHPUnit\Framework\TestCase;

/**
 * Tests the real production logic now that health/search ordering is extracted
 * into App\Services\HealthReport and App\Services\SearchRanker (used by
 * api/health.php and api/search.php). Previously this file reimplemented the
 * logic inline and never touched production code.
 */
class SearchAndHealthTest extends TestCase
{
    // ── Health ──────────────────────────────────────────────────────────────

    public function testHealthIsOkWhenCoreChecksPass(): void
    {
        $checks = ['database' => true, 'uploads_writable' => true, 'rate_limit_dir' => true];
        $payload = HealthReport::build($checks, ['php_version' => PHP_VERSION], []);

        $this->assertSame('ok', $payload['status']);
        $this->assertTrue(HealthReport::isHealthy($checks));
        $this->assertSame(200, HealthReport::httpStatus($checks));
        $this->assertArrayHasKey('database', $payload['checks']);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertSame(['php_version' => PHP_VERSION], $payload['metrics']);
    }

    public function testHealthIsDegradedWhenDatabaseDown(): void
    {
        $checks = ['database' => false, 'uploads_writable' => true];
        $this->assertFalse(HealthReport::isHealthy($checks));
        $this->assertSame('degraded', HealthReport::build($checks)['status']);
        $this->assertSame(503, HealthReport::httpStatus($checks));
    }

    public function testHealthIsDegradedWhenUploadsNotWritable(): void
    {
        $checks = ['database' => true, 'uploads_writable' => false];
        $this->assertSame('degraded', HealthReport::build($checks)['status']);
        $this->assertSame(503, HealthReport::httpStatus($checks));
    }

    public function testHealthIgnoresNonCoreChecksForStatus(): void
    {
        // smtp/pusher being down must NOT make the service "degraded".
        $checks = ['database' => true, 'uploads_writable' => true, 'smtp_reachable' => false, 'pusher_configured' => false];
        $this->assertSame('ok', HealthReport::build($checks)['status']);
    }

    // ── Search ranking ──────────────────────────────────────────────────────

    public function testSearchSortsByDateDescending(): void
    {
        $items = [
            ['date' => '2026-01-01', 'subject' => 'B'],
            ['date' => '2026-06-01', 'subject' => 'A'],
            ['date' => '', 'subject' => 'Member'],
        ];
        $sorted = SearchRanker::sort($items);

        $this->assertSame('2026-06-01', $sorted[0]['date']);
        $this->assertSame('2026-01-01', $sorted[1]['date']);
        $this->assertSame('', $sorted[2]['date'], 'rows without a date sort last');
    }

    public function testSearchTieBreaksBySubjectDescending(): void
    {
        $items = [
            ['date' => '2026-01-01', 'subject' => 'Alpha'],
            ['date' => '2026-01-01', 'subject' => 'Zeta'],
        ];
        $sorted = SearchRanker::sort($items);
        $this->assertSame('Zeta', $sorted[0]['subject']);
        $this->assertSame('Alpha', $sorted[1]['subject']);
    }

    public function testSearchHandlesMissingKeys(): void
    {
        // Must not error when date/subject are absent.
        $sorted = SearchRanker::sort([['id' => 1], ['date' => '2026-02-02', 'subject' => 'X']]);
        $this->assertSame('2026-02-02', $sorted[0]['date']);
    }
}
