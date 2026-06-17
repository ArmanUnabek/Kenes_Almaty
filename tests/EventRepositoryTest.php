<?php

namespace Tests;

use App\Repositories\EventRepository;
use PHPUnit\Framework\TestCase;

class EventRepositoryTest extends TestCase
{
    public function testRepositoryCanBeInstantiated(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $repo = new EventRepository($pdo);
        $this->assertInstanceOf(EventRepository::class, $repo);
    }

    public function testGetAllReturnsItemsAndTotalStructure(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $countStmt = $this->createMock(\PDOStatement::class);
        $listStmt = $this->createMock(\PDOStatement::class);

        $pdo->method('query')->willReturn($countStmt);
        $countStmt->method('fetchColumn')->willReturn('2');

        $pdo->method('prepare')->willReturn($listStmt);
        $listStmt->method('bindValue')->willReturn(true);
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'title' => 'Test', 'event_date' => '2024-01-01'],
        ]);

        $repo = new EventRepository($pdo);
        $result = $repo->getAll(null, 1, 30);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertSame(2, $result['total']);
        $this->assertCount(1, $result['items']);
    }
}
