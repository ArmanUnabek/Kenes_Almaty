<?php

namespace App\Repositories;

class CommissionRepository
{
    public function __construct(private \PDO $db) {}

    public function getById(int $id, ?int $regionId = null): ?array
    {
        $query = 'SELECT * FROM commissions WHERE id = ?';
        $params = [$id];
        if ($regionId) {
            $query .= ' AND region_id = ?';
            $params[] = $regionId;
        }
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getAll(?int $regionId = null, int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;
        $where = '';
        $params = [];
        if ($regionId) {
            $where = ' WHERE region_id = ?';
            $params[] = $regionId;
        }

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM commissions' . $where);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $query = 'SELECT * FROM commissions' . $where . ' ORDER BY sort_order, name LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO commissions (region_id, name, color, sort_order)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['region_id'],
            $data['name'],
            $data['color'] ?? '#6c757d',
            $data['sort_order'] ?? 0,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data, ?int $regionId = null): bool
    {
        $query = 'UPDATE commissions SET name = ?, color = ?, sort_order = ? WHERE id = ?';
        $params = [
            $data['name'],
            $data['color'] ?? '#6c757d',
            $data['sort_order'] ?? 0,
            $id,
        ];
        if ($regionId) {
            $query .= ' AND region_id = ?';
            $params[] = $regionId;
        }
        $stmt = $this->db->prepare($query);
        return $stmt->execute($params);
    }

    public function delete(int $id, ?int $regionId = null): bool
    {
        $query = 'DELETE FROM commissions WHERE id = ?';
        $params = [$id];
        if ($regionId) {
            $query .= ' AND region_id = ?';
            $params[] = $regionId;
        }
        $stmt = $this->db->prepare($query);
        return $stmt->execute($params);
    }
}
