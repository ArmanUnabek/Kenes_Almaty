<?php

namespace Tests;

use App\Services\RegionService;
use PHPUnit\Framework\TestCase;

class RegionServiceTest extends TestCase
{
    public function testParseSettingsFromArray(): void
    {
        $input = ['seq_baseline_incoming' => 100];
        $this->assertSame($input, RegionService::parseSettings($input));
    }

    public function testParseSettingsFromJsonString(): void
    {
        $parsed = RegionService::parseSettings('{"seq_baseline_outgoing":200}');
        $this->assertSame(['seq_baseline_outgoing' => 200], $parsed);
    }

    public function testParseSettingsReturnsEmptyForInvalidInput(): void
    {
        $this->assertSame([], RegionService::parseSettings(null));
        $this->assertSame([], RegionService::parseSettings('not-json'));
        $this->assertSame([], RegionService::parseSettings(''));
    }

    public function testGetSeqBaselineFromSettings(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'settings' => json_encode(['seq_baseline_incoming' => 500]),
        ]);

        $db = $this->createMock(\PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $baseline = RegionService::getSeqBaseline($db, 2, 'incoming_letters');
        $this->assertSame(500, $baseline);
    }

    public function testGetSeqBaselineFallbackForRegionOne(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['settings' => null]);

        $db = $this->createMock(\PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $this->assertSame(1327, RegionService::getSeqBaseline($db, 1, 'incoming_letters'));
        $this->assertSame(1399, RegionService::getSeqBaseline($db, 1, 'outgoing_letters'));
    }

    public function testGetSeqBaselineZeroForNewRegion(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['settings' => '{}']);

        $db = $this->createMock(\PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $this->assertSame(0, RegionService::getSeqBaseline($db, 5, 'incoming_letters'));
    }
}
