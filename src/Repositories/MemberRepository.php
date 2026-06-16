<?php

namespace App\Repositories;

class MemberRepository
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function getById(int $id, ?int $regionId = null): ?array
    {
        $sql = "SELECT * FROM os_members WHERE id = :id";
        $params = [':id' => $id];
        if ($regionId !== null) {
            $sql .= " AND region_id = :region_id";
            $params[':region_id'] = $regionId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getAll(?int $regionId = null, int $limit = 30, int $offset = 0): array
    {
        $sql = "SELECT * FROM os_members";
        $params = [];
        if ($regionId !== null) {
            $sql .= " WHERE region_id = :region_id";
            $params[':region_id'] = $regionId;
        }
        $sql .= " ORDER BY last_name, first_name LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO os_members (first_name, last_name, region_id, commission_id, email, phone, created_at)
                VALUES (:first_name, :last_name, :region_id, :commission_id, :email, :phone, NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':first_name' => $data['first_name'] ?? null,
            ':last_name' => $data['last_name'] ?? null,
            ':region_id' => $data['region_id'] ?? null,
            ':commission_id' => $data['commission_id'] ?? null,
            ':email' => $data['email'] ?? null,
            ':phone' => $data['phone'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];
        $allowed = ['first_name','last_name','region_id','commission_id','email','phone'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "{$f} = :{$f}";
                $params[":{$f}"] = $data[$f];
            }
        }
        if (empty($fields)) {
            return false;
        }
        $sql = "UPDATE os_members SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM os_members WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
