<?php

namespace App\Repositories;

class EventRepository
{
    public function __construct(private \PDO $db)
    {
    }

    public function getRegionId(int $id): ?int
    {
        $stmt = $this->db->prepare('SELECT region_id FROM events WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (int)$value : null;
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
                    COUNT(ea.id) AS attendees_total,
                    COALESCE(SUM(ea.attended), 0) AS attendees_present
                FROM events e
                LEFT JOIN event_attendees ea ON ea.event_id = e.id
                WHERE e.region_id = ?
                GROUP BY e.id
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
                    COUNT(ea.id) AS attendees_total,
                    COALESCE(SUM(ea.attended), 0) AS attendees_present
                FROM events e
                LEFT JOIN event_attendees ea ON ea.event_id = e.id
                GROUP BY e.id
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
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO events (region_id, title, event_date, location, location_url, participants_total, attendance_percent, notes, description, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $regionId,
                $data['title'] ?? '',
                $data['event_date'] ?? date('Y-m-d'),
                $data['location'] ?? null,
                self::sanitizeLocationUrl($data['location_url'] ?? null),
                (int)($data['participants_total'] ?? 0),
                (float)($data['attendance_percent'] ?? 0),
                $data['notes'] ?? null,
                $data['description'] ?? null,
                $createdBy,
            ]);
            $eventId = (int)$this->db->lastInsertId();

            $this->syncKpi($eventId, $data['kpi'] ?? []);
            $this->syncAttendees($eventId, $data['attendees'] ?? []);

            $this->db->commit();
            return $eventId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Строго ограниченное частичное обновление (только колонки из белого списка).
     * Значения должны быть уже провалидированы вызывающим кодом.
     */
    public function patch(int $id, array $columns): void
    {
        $allowed = ['event_date'];
        $set = [];
        $params = [];
        foreach ($columns as $col => $value) {
            if (!in_array($col, $allowed, true)) {
                continue;
            }
            $set[] = "{$col} = ?";
            $params[] = $value;
        }
        if (empty($set)) {
            return;
        }
        $params[] = $id;
        $stmt = $this->db->prepare('UPDATE events SET ' . implode(', ', $set) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    public function update(int $id, array $data): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE events SET title = ?, event_date = ?, location = ?, location_url = ?, participants_total = ?, attendance_percent = ?, notes = ?, description = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['title'] ?? '',
                $data['event_date'] ?? date('Y-m-d'),
                $data['location'] ?? null,
                self::sanitizeLocationUrl($data['location_url'] ?? null),
                (int)($data['participants_total'] ?? 0),
                (float)($data['attendance_percent'] ?? 0),
                $data['notes'] ?? null,
                $data['description'] ?? null,
                $id,
            ]);

            if (array_key_exists('kpi', $data) && is_array($data['kpi'])) {
                $this->syncKpi($id, $data['kpi'], true);
            }
            if (array_key_exists('attendees', $data) && is_array($data['attendees'])) {
                $this->syncAttendees($id, $data['attendees'], true);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM event_kpi WHERE event_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM event_attendees WHERE event_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private static function sanitizeLocationUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $allowed = ['https://2gis.kz/', 'https://go.2gis.com/', 'https://www.2gis.kz/'];
        foreach ($allowed as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return $url;
            }
        }
        return null;
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
