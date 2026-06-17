<?php

namespace Tests;

use App\Services\LetterPersistenceService;
use PHPUnit\Framework\TestCase;

class LetterPersistenceServiceTest extends TestCase
{
    public function testNormalizeMembersPayload(): void
    {
        $result = LetterPersistenceService::normalizeMembersPayload([
            ['member_id' => 5, 'is_lead' => true],
            7,
        ]);
        $this->assertCount(2, $result);
        $this->assertSame(5, $result[0]['member_id']);
        $this->assertTrue($result[0]['is_lead']);
        $this->assertSame(7, $result[1]['member_id']);
    }

    public function testNormalizeRecipientsPayload(): void
    {
        $result = LetterPersistenceService::normalizeRecipientsPayload([
            'Организация А',
            ['recipient' => 'Иванов'],
            '',
        ]);
        $this->assertSame(['Организация А', 'Иванов'], $result);
    }
}
