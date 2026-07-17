<?php

namespace App\Middleware;

use PDO;

class ApiKeyAuth
{
    public static function authenticate(PDO $db): ?array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return null;
        }

        $rawKey = $m[1];
        if (!str_starts_with($rawKey, 'ak_')) {
            return null;
        }

        $hash = hash('sha256', $rawKey);

        $stmt = $db->prepare("
            SELECT ak.id AS api_key_id, ak.permissions, ak.expires_at,
                   u.id, u.username, u.full_name, u.role, u.region_id, u.is_active, u.email
            FROM api_keys ak
            JOIN users u ON ak.user_id = u.id
            WHERE ak.key_hash = ? AND ak.is_active = 1 AND u.is_active = 1
        ");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if ($row['expires_at'] && strtotime((string)$row['expires_at']) < time()) {
            return null;
        }

        $db->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?")
           ->execute([$row['api_key_id']]);

        return [
            'id'                  => $row['id'],
            'username'            => $row['username'],
            'full_name'           => $row['full_name'],
            'role'                => $row['role'],
            'region_id'           => $row['region_id'],
            'is_active'           => $row['is_active'],
            'email'               => $row['email'],
            'api_key_id'          => $row['api_key_id'],
            'api_key_permissions' => $row['permissions'] ? json_decode($row['permissions'], true) : null,
        ];
    }
}
