<?php

namespace App\Services;

use PDO;

class ApiKeyService
{
    private const KEY_PREFIX = 'ak_';
    private const KEY_LENGTH = 48;

    public static function createKey(PDO $db, int $userId, string $name, ?array $permissions = null, ?int $expiresIn = null): array
    {
        $rawKey = self::KEY_PREFIX . bin2hex(random_bytes(self::KEY_LENGTH));
        $hash = hash('sha256', $rawKey);

        $expiresAt = null;
        if ($expiresIn && $expiresIn > 0) {
            $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        }

        $stmt = $db->prepare(
            "INSERT INTO api_keys (user_id, name, key_hash, permissions, expires_at) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $userId,
            substr($name, 0, 255),
            $hash,
            $permissions ? json_encode($permissions) : null,
            $expiresAt,
        ]);

        return [
            'id' => (int)$db->lastInsertId(),
            'key' => $rawKey,
            'name' => $name,
            'permissions' => $permissions,
            'expires_at' => $expiresAt,
        ];
    }

    public static function validateKey(PDO $db, string $key): ?array
    {
        if (!str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }

        $hash = hash('sha256', $key);

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

    public static function revokeKey(PDO $db, int $keyId, ?int $userId = null): bool
    {
        if ($userId !== null) {
            $stmt = $db->prepare("UPDATE api_keys SET is_active = 0 WHERE id = ? AND user_id = ?");
            $stmt->execute([$keyId, $userId]);
        } else {
            $stmt = $db->prepare("UPDATE api_keys SET is_active = 0 WHERE id = ?");
            $stmt->execute([$keyId]);
        }
        return $stmt->rowCount() > 0;
    }

    public static function listKeys(PDO $db, int $userId): array
    {
        $stmt = $db->prepare(
            "SELECT id, name, permissions, last_used_at, expires_at, is_active, created_at
             FROM api_keys WHERE user_id = ? ORDER BY id DESC"
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['permissions'] = $row['permissions'] ? json_decode($row['permissions'], true) : null;
        }

        return $rows;
    }

    public static function maskKey(string $rawKey): string
    {
        if (strlen($rawKey) <= 8) {
            return $rawKey;
        }
        return substr($rawKey, 0, 6) . '...' . substr($rawKey, -4);
    }
}
