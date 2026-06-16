<?php

namespace App\Repositories;

class LetterRepository
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function getById(int $id, string $type = 'incoming'): ?array
    {
        $table = $type === 'outgoing' ? 'outgoing_letters' : 'incoming_letters';
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getAll(string $type = 'incoming', ?int $regionId = null, int $limit = 50, int $offset = 0): array
    {
        $table = $type === 'outgoing' ? 'outgoing_letters' : 'incoming_letters';
        $sql = "SELECT * FROM {$table}";
        $params = [];
        if ($regionId !== null) {
            $sql .= " WHERE region_id = :region_id";
            $params[':region_id'] = $regionId;
        }
        $sql .= " ORDER BY date DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(array $data, string $type = 'incoming'): int
    {
        $table = $type === 'outgoing' ? 'outgoing_letters' : 'incoming_letters';
        $sql = "INSERT INTO {$table} (region_id, date, subject, body, created_by, created_at)
                VALUES (:region_id, :date, :subject, :body, :created_by, NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':region_id' => $data['region_id'] ?? null,
            ':date' => $data['date'] ?? null,
            ':subject' => $data['subject'] ?? null,
            ':body' => $data['body'] ?? null,
            ':created_by' => $data['created_by'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data, string $type = 'incoming'): bool
    {
        $table = $type === 'outgoing' ? 'outgoing_letters' : 'incoming_letters';
        $fields = [];
        $params = [':id' => $id];
        $allowed = ['region_id','date','subject','body','updated_at'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "{$f} = :{$f}";
                $params[":{$f}"] = $data[$f];
            }
        }
        if (empty($fields)) {
            return false;
        }
        $sql = "UPDATE {$table} SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(int $id, string $type = 'incoming'): bool
    {
        $table = $type === 'outgoing' ? 'outgoing_letters' : 'incoming_letters';
        $stmt = $this->db->prepare("DELETE FROM {$table} WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function linkLetters(int $incomingId, int $outgoingId): void
    {
        $stmt = $this->db->prepare("INSERT INTO letter_relations (incoming_id, outgoing_id, created_at) VALUES (:i, :o, NOW())");
        $stmt->execute([':i' => $incomingId, ':o' => $outgoingId]);
    }
}
