<?php

namespace App\Repositories;

class EventRepository
{
    public function __construct(private \PDO $db)
    {
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM events WHERE id = ?');
        $stmt->execute([$id]);
        $event = $stmt->fetch();
        if (!$event) {
            return null;
        }

        $kpi = $this->db->prepare('SELECT * FROM event_kpi WHERE event_id = ? ORDER BY id');
        $kpi->execute([$id]);
        $event['kpi'] = $kpi->fetchAll();

        $att = $this->db->prepare('SELECT id, full_name, attended FROM event_attendees WHERE event_id = ? ORDER BY id');
        $att->execute([$id]);
        $event['attendees'] = $att->fetchAll();

        return $event;
    }

    public function getAll(?int $regionId, int $page = 1, int $limit = 30): array
    {
        $offset = ($page - 1) * $limit;

        if ($regionId) {
            $count = $this->db->prepare('SELECT COUNT(*) FROM events e WHERE e.region_id = ?');
            $count->execute([$regionId]);
            $total = (int)$count->fetchColumn();

            $stmt = $this->db->prepare("
                SELECT e.*,
                    (SELECT COUNT(*) FROM event_attendees ea WHERE ea.event_id = e.id) AS attendees_total,
                    (SELECT COALESCE(SUM(ea.attended),0) FROM event_attendees ea WHERE ea.event_id = e.id) AS attendees_present
                FROM events e
                WHERE e.region_id = ?
                ORDER BY e.event_date DESC, e.id DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, $regionId, \PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $total = (int)$this->db->query('SELECT COUNT(*) FROM events')->fetchColumn();
            $stmt = $this->db->prepare("
                SELECT e.*,
                    (SELECT COUNT(*) FROM event_attendees ea WHERE ea.event_id = e.id) AS attendees_total,
                    (SELECT COALESCE(SUM(ea.attended),0) FROM event_attendees ea WHERE ea.event_id = e.id) AS attendees_present
                FROM events e
                ORDER BY e.event_date DESC, e.id DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
            $stmt->execute();
        }

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function create(array $data, ?int $regionId, ?int $createdBy): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO events (region_id, title, event_date, location, participants_total, attendance_percent, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $regionId,
            $data['title'] ?? '',
            $data['event_date'] ?? date('Y-m-d'),
            $data['location'] ?? null,
            (int)($data['participants_total'] ?? 0),
            (float)($data['attendance_percent'] ?? 0),
            $data['notes'] ?? null,
            $createdBy,
        ]);
        $eventId = (int)$this->db->lastInsertId();

        $this->syncKpi($eventId, $data['kpi'] ?? []);
        $this->syncAttendees($eventId, $data['attendees'] ?? []);

        return $eventId;
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare("
            UPDATE events SET title = ?, event_date = ?, location = ?, participants_total = ?, attendance_percent = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['title'] ?? '',
            $data['event_date'] ?? date('Y-m-d'),
            $data['location'] ?? null,
            (int)($data['participants_total'] ?? 0),
            (float)($data['attendance_percent'] ?? 0),
            $data['notes'] ?? null,
            $id,
        ]);

        if (array_key_exists('kpi', $data) && is_array($data['kpi'])) {
            $this->syncKpi($id, $data['kpi'], true);
        }
        if (array_key_exists('attendees', $data) && is_array($data['attendees'])) {
            $this->syncAttendees($id, $data['attendees'], true);
        }
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM event_kpi WHERE event_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM event_attendees WHERE event_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
    }

    private function syncKpi(int $eventId, array $rows, bool $replace = false): void
    {
        if ($replace) {
            $this->db->prepare('DELETE FROM event_kpi WHERE event_id = ?')->execute([$eventId]);
        }
        if (!$rows) {
            return;
        }
        $ins = $this->db->prepare('INSERT INTO event_kpi (event_id, metric, value_numeric, value_text) VALUES (?, ?, ?, ?)');
        foreach ($rows as $k) {
            $ins->execute([$eventId, $k['metric'] ?? '', $k['value_numeric'] ?? null, $k['value_text'] ?? null]);
        }
    }

    private function syncAttendees(int $eventId, array $rows, bool $replace = false): void
    {
        if ($replace) {
            $this->db->prepare('DELETE FROM event_attendees WHERE event_id = ?')->execute([$eventId]);
        }
        if (!$rows) {
            return;
        }
        $ins = $this->db->prepare('INSERT INTO event_attendees (event_id, full_name, attended) VALUES (?, ?, ?)');
        foreach ($rows as $a) {
            $fullName = trim($a['full_name'] ?? '');
            if ($fullName === '') {
                continue;
            }
            $ins->execute([$eventId, $fullName, !empty($a['attended']) ? 1 : 0]);
        }
    }
}
