<?php

namespace App\Repositories;

class UserRepository
{
    public function __construct(private \PDO $db) {}

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT u.id, u.username, u.email, u.full_name, u.role, u.region_id, u.is_active, u.last_login, u.created_at,
                   r.name_ru AS region_name
            FROM users u
            LEFT JOIN regions r ON u.region_id = r.id
            WHERE u.id = ?
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getAll(?int $regionId = null, int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;
        $where = '';
        $params = [];
        if ($regionId) {
            $where = ' WHERE u.region_id = ?';
            $params[] = $regionId;
        }

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM users u' . $where);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = '
            SELECT u.id, u.username, u.email, u.full_name, u.role, u.region_id, u.is_active, u.last_login, u.created_at,
                   r.name_ru AS region_name
            FROM users u
            LEFT JOIN regions r ON u.region_id = r.id
        ' . $where . ' ORDER BY u.full_name LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $this->db->prepare($sql);
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
            INSERT INTO users (username, email, password_hash, full_name, role, region_id, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['username'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['full_name'],
            $data['role'] ?? 'viewer',
            $data['region_id'] ?? null,
            !empty($data['is_active']) ? 1 : 0,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        foreach (['username', 'email', 'full_name', 'role'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }
        if (array_key_exists('region_id', $data)) {
            $fields[] = 'region_id = ?';
            $params[] = $data['region_id'] ?: null;
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = ?';
            $params[] = !empty($data['is_active']) ? 1 : 0;
        }
        if (!empty($data['password'])) {
            $fields[] = 'password_hash = ?';
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deactivate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET is_active = FALSE WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE username = ?';
        $params = [$username];
        if ($excludeId) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }
}
