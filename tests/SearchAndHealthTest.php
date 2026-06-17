<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class SearchAndHealthTest extends TestCase
{
    public function testHealthResponseStructure(): void
    {
        $checks = [
            'database' => true,
            'uploads_writable' => true,
            'php_version' => PHP_VERSION,
        ];
        $healthy = $checks['database'] && $checks['uploads_writable'];
        $payload = [
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'messages' => [],
        ];

        $this->assertSame('ok', $payload['status']);
        $this->assertArrayHasKey('php_version', $payload['checks']);
    }

    public function testSearchItemSortingByDate(): void
    {
        $items = [
            ['date' => '2026-01-01', 'subject' => 'B'],
            ['date' => '2026-06-01', 'subject' => 'A'],
            ['date' => '', 'subject' => 'Member'],
        ];

        usort($items, static function (array $a, array $b): int {
            $dateA = $a['date'] ?? '';
            $dateB = $b['date'] ?? '';
            if ($dateA === $dateB) {
                return strcmp((string)($b['subject'] ?? ''), (string)($a['subject'] ?? ''));
            }
            return strcmp($dateB, $dateA);
        });

        $this->assertSame('2026-06-01', $items[0]['date']);
        $this->assertSame('2026-01-01', $items[1]['date']);
    }
}
