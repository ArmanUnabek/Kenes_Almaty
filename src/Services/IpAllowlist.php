<?php

namespace App\Services;

use PDO;

class IpAllowlist
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Check if IP is allowed for the given user.
     * Returns true if allowed (or no allowlist configured), false if blocked.
     */
    public function isAllowed(int $userId, string $ip): bool
    {
        $allowedIps = $this->getAllowedIps($userId);
        if ($allowedIps === null) {
            return true;
        }
        return in_array($ip, $allowedIps, true);
    }

    /**
     * Update the allowed IPs for a user.
     * Pass null or empty array to remove all restrictions.
     */
    public function setAllowedIps(int $userId, ?array $ips): void
    {
        $json = ($ips === null || $ips === []) ? null : json_encode(array_values($ips), JSON_ENCODE_FLAGS);
        $this->db->prepare('UPDATE users SET allowed_ips = ? WHERE id = ?')
            ->execute([$json, $userId]);
    }

    /**
     * Returns true if an admin is logging in from an IP not in their allowlist.
     * Used to trigger additional confirmation / notification.
     */
    public function needsConfirmation(int $userId, string $ip): bool
    {
        return !$this->isAllowed($userId, $ip);
    }

    /**
     * Get the allowed IPs array for a user, or null if no restriction.
     */
    public function getAllowedIps(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT allowed_ips FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $raw = $stmt->fetchColumn();

        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return array_map('strval', $decoded);
    }
}
