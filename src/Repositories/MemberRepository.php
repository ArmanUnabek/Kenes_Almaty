<?php

namespace App\Repositories;

class MemberRepository
{
    public function __construct(private \PDO $db) {}

    public static function photoApiUrl(int $memberId, ?string $cacheKey = null): string
    {
        $url = '/api/member_photo.php?member_id=' . $memberId;
        if ($cacheKey !== null && $cacheKey !== '') {
            $url .= '&v=' . rawurlencode($cacheKey);
        }
        return $url;
    }

    private static function resolvePhotoUrl(array $member): ?string
    {
        if (empty($member['photo_path']) || empty($member['id'])) {
            return null;
        }
        $relative = ltrim((string)$member['photo_path'], '/');
        if (str_starts_with($relative, 'api/member_photo.php')) {
            return '/' . $relative;
        }
        $full = defined('APP_ROOT') ? APP_ROOT . '/' . $relative : $relative;
        $cacheKey = (is_string($full) && is_file($full)) ? (string)filemtime($full) : null;
        return self::photoApiUrl((int)$member['id'], $cacheKey);
    }

    public function getById(int $id, ?int $regionId = null): ?array
    {
        $query = "
            SELECT m.*, c.name as commission_name, c.color as commission_color
            FROM os_members m
            LEFT JOIN commissions c ON m.commission_id = c.id
            WHERE m.id = ?
        ";
        $params = [$id];

        if ($regionId) {
            $query .= " AND m.region_id = ?";
            $params[] = $regionId;
        }

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $member = $stmt->fetch();

        if ($member) {
            $photoUrl = self::resolvePhotoUrl($member);
            if ($photoUrl) {
                $member['photo_url'] = $photoUrl;
            }
        }

        return $member ?: null;
    }

    public function getAll(int $page = 1, int $limit = 20, ?int $regionId = null, ?int $commissionId = null): array
    {
        $offset = ($page - 1) * $limit;

        $query = "
            SELECT m.*, c.name as commission_name, c.color as commission_color, c.sort_order as commission_sort_order
            FROM os_members m
            LEFT JOIN commissions c ON m.commission_id = c.id
            WHERE m.status = 'active'
        ";
        $params = [];

        if ($regionId) {
            $query .= " AND m.region_id = ?";
            $params[] = $regionId;
        }

        if ($commissionId) {
            $query .= " AND m.commission_id = ?";
            $params[] = $commissionId;
        }

        $countQuery = str_replace(
            ['SELECT m.*, c.name as commission_name, c.color as commission_color, c.sort_order as commission_sort_order', 'ORDER BY'],
            ['SELECT COUNT(*) as cnt', 'LIMIT 1 ORDER BY'],
            $query
        );

        $stmtCount = $this->db->prepare($countQuery);
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetch()['cnt'];

        $query .= " ORDER BY (m.commission_id IS NULL) DESC, COALESCE(c.sort_order, 999), m.full_name LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $members = $stmt->fetchAll();

        foreach ($members as &$member) {
            $photoUrl = self::resolvePhotoUrl($member);
            if ($photoUrl) {
                $member['photo_url'] = $photoUrl;
            }
        }

        return [
            'items' => $members,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ];
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO os_members (region_id, full_name, position, position_kz, organization, organization_kz, commission_id, email, phone, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['region_id'] ?? 1,
            $data['full_name'],
            $data['position'] ?? null,
            $data['position_kz'] ?? null,
            $data['organization'] ?? null,
            $data['organization_kz'] ?? null,
            $data['commission_id'] ?? null,
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['status'] ?? 'active'
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data, ?int $regionId = null): bool
    {
        $query = "
            UPDATE os_members 
            SET full_name = ?, position = ?, position_kz = ?, organization = ?, organization_kz = ?, commission_id = ?, 
                email = ?, phone = ?, status = ?
            WHERE id = ?
        ";
        $params = [
            $data['full_name'],
            $data['position'] ?? null,
            $data['position_kz'] ?? null,
            $data['organization'] ?? null,
            $data['organization_kz'] ?? null,
            $data['commission_id'] ?? null,
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['status'] ?? 'active',
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
        $query = "UPDATE os_members SET status = 'inactive' WHERE id = ?";
        $params = [$id];
        if ($regionId) {
            $query .= ' AND region_id = ?';
            $params[] = $regionId;
        }
        $stmt = $this->db->prepare($query);
        return $stmt->execute($params);
    }

    public function getByCommission(int $commissionId, ?int $regionId = null): array
    {
        $query = "
            SELECT * FROM os_members 
            WHERE commission_id = ? AND status = 'active'
        ";
        $params = [$commissionId];

        if ($regionId) {
            $query .= " AND region_id = ?";
            $params[] = $regionId;
        }

        $query .= " ORDER BY full_name";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $list = $stmt->fetchAll();
        foreach ($list as &$member) {
            $photoUrl = self::resolvePhotoUrl($member);
            if ($photoUrl) {
                $member['photo_url'] = $photoUrl;
            }
        }
        unset($member);
        return $list;
    }

    public function updatePhotoPath(int $id, string $photoPath): bool
    {
        $stmt = $this->db->prepare("UPDATE os_members SET photo_path = ? WHERE id = ?");
        return $stmt->execute([$photoPath, $id]);
    }

    public function getPhotoPath(int $id): ?string
    {
        $stmt = $this->db->prepare("SELECT photo_path FROM os_members WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ?: null;
    }
}
