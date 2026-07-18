<?php

namespace App\Repositories;

/**
 * Data access for council meetings (заседания): the meeting row plus its agenda
 * items and attendance, region-scoped like EventRepository. Quorum is derived from
 * present attendees vs. the active council membership.
 */
class MeetingRepository
{
    public function __construct(private \PDO $db)
    {
    }

    public function getRegionId(int $id): ?int
    {
        $stmt = $this->db->prepare('SELECT region_id FROM meetings WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null ? (int)$value : null;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM meetings WHERE id = ?');
        $stmt->execute([$id]);
        $meeting = $stmt->fetch();
        if (!$meeting) {
            return null;
        }

        $agenda = $this->db->prepare(
            'SELECT ai.*, m.full_name AS presenter_name
             FROM meeting_agenda_items ai
             LEFT JOIN os_members m ON ai.presenter_member_id = m.id
             WHERE ai.meeting_id = ? ORDER BY ai.item_order ASC, ai.id ASC'
        );
        $agenda->execute([$id]);
        $meeting['agenda'] = $agenda->fetchAll();

        $att = $this->db->prepare(
            'SELECT a.id, a.member_id, a.present, m.full_name
             FROM meeting_attendance a
             JOIN os_members m ON a.member_id = m.id
             WHERE a.meeting_id = ? ORDER BY m.full_name ASC'
        );
        $att->execute([$id]);
        $meeting['attendance'] = $att->fetchAll();

        $meeting['quorum'] = $this->quorum($id, isset($meeting['region_id']) ? (int)$meeting['region_id'] : null);

        return $meeting;
    }

    public function getAll(?int $regionId, int $page = 1, int $limit = 30): array
    {
        $offset = ($page - 1) * $limit;
        $where = $regionId ? 'WHERE m.region_id = ?' : '';

        $countSql = "SELECT COUNT(*) FROM meetings m {$where}";
        $count = $this->db->prepare($countSql);
        $count->execute($regionId ? [$regionId] : []);
        $total = (int)$count->fetchColumn();

        $sql = "
            SELECT m.*,
                COUNT(a.id) AS attendance_total,
                COALESCE(SUM(a.present), 0) AS attendance_present
            FROM meetings m
            LEFT JOIN meeting_attendance a ON a.meeting_id = m.id
            {$where}
            GROUP BY m.id
            ORDER BY m.meeting_date DESC, m.id DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $this->db->prepare($sql);
        $i = 1;
        if ($regionId) {
            $stmt->bindValue($i++, $regionId, \PDO::PARAM_INT);
        }
        $stmt->bindValue($i++, $limit, \PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * Quorum = a majority of the active council is present.
     * total = active os_members in the meeting's region (all regions if null).
     */
    public function quorum(int $meetingId, ?int $regionId): array
    {
        $p = $this->db->prepare("SELECT COALESCE(SUM(present),0) FROM meeting_attendance WHERE meeting_id = ?");
        $p->execute([$meetingId]);
        $present = (int)$p->fetchColumn();

        if ($regionId) {
            $t = $this->db->prepare("SELECT COUNT(*) FROM os_members WHERE status = 'active' AND region_id = ?");
            $t->execute([$regionId]);
        } else {
            $t = $this->db->query("SELECT COUNT(*) FROM os_members WHERE status = 'active'");
        }
        $totalMembers = (int)$t->fetchColumn();

        return [
            'present'       => $present,
            'total_members' => $totalMembers,
            'has_quorum'    => $totalMembers > 0 && $present * 2 > $totalMembers,
        ];
    }

    public function create(array $data, ?int $regionId, ?int $createdBy): int
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO meetings (region_id, title, meeting_date, meeting_time, location, type, status, protocol_number, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $regionId,
                $data['title'] ?? '',
                $data['meeting_date'] ?? date('Y-m-d'),
                $data['meeting_time'] ?? null,
                $data['location'] ?? null,
                self::normalizeType($data['type'] ?? null),
                self::normalizeStatus($data['status'] ?? null),
                $data['protocol_number'] ?? null,
                $data['notes'] ?? null,
                $createdBy,
            ]);
            $meetingId = (int)$this->db->lastInsertId();

            $this->syncAgenda($meetingId, $data['agenda'] ?? []);
            $this->syncAttendance($meetingId, $data['attendance'] ?? []);

            $this->db->commit();
            return $meetingId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE meetings SET title = ?, meeting_date = ?, meeting_time = ?, location = ?, type = ?, status = ?, protocol_number = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['title'] ?? '',
                $data['meeting_date'] ?? date('Y-m-d'),
                $data['meeting_time'] ?? null,
                $data['location'] ?? null,
                self::normalizeType($data['type'] ?? null),
                self::normalizeStatus($data['status'] ?? null),
                $data['protocol_number'] ?? null,
                $data['notes'] ?? null,
                $id,
            ]);

            if (array_key_exists('agenda', $data) && is_array($data['agenda'])) {
                $this->syncAgenda($id, $data['agenda'], true);
            }
            if (array_key_exists('attendance', $data) && is_array($data['attendance'])) {
                $this->syncAttendance($id, $data['attendance'], true);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        // meeting_agenda_items / meeting_attendance cascade via FK, but delete
        // explicitly too so SQLite (FKs off by default) stays consistent.
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM meeting_agenda_items WHERE meeting_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM meeting_attendance WHERE meeting_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM meetings WHERE id = ?')->execute([$id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private static function normalizeType(?string $t): string
    {
        return in_array($t, ['regular', 'extraordinary'], true) ? $t : 'regular';
    }

    private static function normalizeStatus(?string $s): string
    {
        return in_array($s, ['planned', 'in_progress', 'held', 'cancelled'], true) ? $s : 'planned';
    }

    private function syncAgenda(int $meetingId, array $rows, bool $replace = false): void
    {
        if ($replace) {
            $this->db->prepare('DELETE FROM meeting_agenda_items WHERE meeting_id = ?')->execute([$meetingId]);
        }
        if (!$rows) {
            return;
        }
        $ins = $this->db->prepare(
            'INSERT INTO meeting_agenda_items (meeting_id, item_order, title, description, presenter_member_id, decision_text)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $i => $item) {
            $title = trim($item['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $ins->execute([
                $meetingId,
                (int)($item['item_order'] ?? $i),
                $title,
                $item['description'] ?? null,
                !empty($item['presenter_member_id']) ? (int)$item['presenter_member_id'] : null,
                $item['decision_text'] ?? null,
            ]);
        }
    }

    private function syncAttendance(int $meetingId, array $rows, bool $replace = false): void
    {
        if ($replace) {
            $this->db->prepare('DELETE FROM meeting_attendance WHERE meeting_id = ?')->execute([$meetingId]);
        }
        if (!$rows) {
            return;
        }
        $ins = $this->db->prepare(
            'INSERT INTO meeting_attendance (meeting_id, member_id, present) VALUES (?, ?, ?)'
        );
        $seen = [];
        foreach ($rows as $a) {
            $memberId = (int)($a['member_id'] ?? 0);
            if ($memberId <= 0 || isset($seen[$memberId])) {
                continue; // skip invalid / duplicate (UNIQUE would otherwise throw)
            }
            $seen[$memberId] = true;
            $ins->execute([$meetingId, $memberId, !empty($a['present']) ? 1 : 0]);
        }
    }
}
