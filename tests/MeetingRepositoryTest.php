<?php

namespace Tests;

use App\Repositories\MeetingRepository;
use Tests\Support\RepositoryTestCase;

/**
 * Unit-tests App\Repositories\MeetingRepository against in-memory SQLite:
 * create/read with agenda + attendance, region scoping, quorum math, and
 * update/delete of the child rows.
 */
class MeetingRepositoryTest extends RepositoryTestCase
{
    private MeetingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp(); // regions, users, commissions, os_members
        $this->db->exec("CREATE TABLE meetings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, region_id INTEGER, title TEXT, meeting_date TEXT,
            meeting_time TEXT, location TEXT, type TEXT DEFAULT 'regular', status TEXT DEFAULT 'planned',
            protocol_number TEXT, notes TEXT, created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT)");
        $this->db->exec("CREATE TABLE meeting_agenda_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT, meeting_id INTEGER, item_order INTEGER DEFAULT 0,
            title TEXT, description TEXT, presenter_member_id INTEGER, decision_text TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $this->db->exec("CREATE TABLE meeting_attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT, meeting_id INTEGER, member_id INTEGER,
            present INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(meeting_id, member_id))");
        $this->repo = new MeetingRepository($this->db);
    }

    private function seedMember(int $id, int $regionId, string $name): void
    {
        $this->db->prepare("INSERT INTO os_members (id, region_id, full_name, status) VALUES (?, ?, ?, 'active')")
            ->execute([$id, $regionId, $name]);
    }

    public function testCreateAndReadWithAgendaAndAttendance(): void
    {
        $this->seedMember(1, 1, 'Иванов И.');
        $this->seedMember(2, 1, 'Петров П.');

        $id = $this->repo->create([
            'title' => 'Заседание №1',
            'meeting_date' => '2026-08-01',
            'type' => 'regular',
            'status' => 'held',
            'agenda' => [
                ['item_order' => 1, 'title' => 'Второй вопрос'],
                ['item_order' => 0, 'title' => 'Первый вопрос', 'presenter_member_id' => 1, 'decision_text' => 'Принято'],
            ],
            'attendance' => [
                ['member_id' => 1, 'present' => 1],
                ['member_id' => 2, 'present' => 0],
            ],
        ], 1, 7);

        $m = $this->repo->getById($id);
        $this->assertSame('Заседание №1', $m['title']);
        // Agenda ordered by item_order.
        $this->assertSame('Первый вопрос', $m['agenda'][0]['title']);
        $this->assertSame('Иванов И.', $m['agenda'][0]['presenter_name']);
        $this->assertSame('Второй вопрос', $m['agenda'][1]['title']);
        $this->assertCount(2, $m['attendance']);
    }

    public function testTypeAndStatusAreNormalized(): void
    {
        $id = $this->repo->create(['title' => 'X', 'meeting_date' => '2026-08-01', 'type' => 'bogus', 'status' => 'bogus'], 1, null);
        $m = $this->repo->getById($id);
        $this->assertSame('regular', $m['type']);
        $this->assertSame('planned', $m['status']);
    }

    public function testQuorumMajorityOfActiveMembers(): void
    {
        // 3 active members in region 1.
        $this->seedMember(1, 1, 'A'); $this->seedMember(2, 1, 'B'); $this->seedMember(3, 1, 'C');
        // A member in another region must not count toward region 1's quorum.
        $this->seedMember(4, 2, 'D');

        $id = $this->repo->create(['title' => 'M', 'meeting_date' => '2026-08-01', 'attendance' => [
            ['member_id' => 1, 'present' => 1],
            ['member_id' => 2, 'present' => 1],
            ['member_id' => 3, 'present' => 0],
        ]], 1, null);

        $q = $this->repo->getById($id)['quorum'];
        $this->assertSame(2, $q['present']);
        $this->assertSame(3, $q['total_members']);
        $this->assertTrue($q['has_quorum'], '2 of 3 present is a majority');
    }

    public function testQuorumFailsWithoutMajority(): void
    {
        $this->seedMember(1, 1, 'A'); $this->seedMember(2, 1, 'B'); $this->seedMember(3, 1, 'C');
        $id = $this->repo->create(['title' => 'M', 'meeting_date' => '2026-08-01', 'attendance' => [
            ['member_id' => 1, 'present' => 1],
        ]], 1, null);
        $this->assertFalse($this->repo->getById($id)['quorum']['has_quorum']);
    }

    public function testRegionScopingInGetAll(): void
    {
        $this->repo->create(['title' => 'R1', 'meeting_date' => '2026-08-01'], 1, null);
        $this->repo->create(['title' => 'R2', 'meeting_date' => '2026-08-02'], 2, null);

        $r1 = $this->repo->getAll(1);
        $this->assertSame(1, $r1['total']);
        $this->assertSame('R1', $r1['items'][0]['title']);
        // Superadmin (null region) sees both.
        $this->assertSame(2, $this->repo->getAll(null)['total']);
    }

    public function testUpdateReplacesAgendaAndDeleteCascades(): void
    {
        $this->seedMember(1, 1, 'A');
        $id = $this->repo->create(['title' => 'M', 'meeting_date' => '2026-08-01',
            'agenda' => [['title' => 'old']], 'attendance' => [['member_id' => 1, 'present' => 1]]], 1, null);

        $this->repo->update($id, ['title' => 'M2', 'meeting_date' => '2026-08-01',
            'agenda' => [['title' => 'new1'], ['title' => 'new2']]]);
        $m = $this->repo->getById($id);
        $this->assertSame('M2', $m['title']);
        $this->assertCount(2, $m['agenda']);
        $this->assertSame('new1', $m['agenda'][0]['title']);

        $this->repo->delete($id);
        $this->assertNull($this->repo->getById($id));
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM meeting_agenda_items')->fetchColumn());
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM meeting_attendance')->fetchColumn());
    }
}
