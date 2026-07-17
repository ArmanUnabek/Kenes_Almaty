<?php

namespace App\Services;

class PushService
{
    public static function subscribe(\PDO $db, int $userId, string $endpoint, string $p256dh, string $auth): void
    {
        $isMysql = stripos($db->getAttribute(\PDO::ATTR_DRIVER_NAME), 'mysql') !== false;

        if ($isMysql) {
            $db->prepare(
                'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth)'
            )->execute([$userId, $endpoint, $p256dh, $auth]);
        } else {
            $stmt = $db->prepare('SELECT id FROM push_subscriptions WHERE user_id = ? AND endpoint = ?');
            $stmt->execute([$userId, $endpoint]);
            if ($stmt->fetch()) {
                $db->prepare('UPDATE push_subscriptions SET p256dh = ?, auth = ? WHERE user_id = ? AND endpoint = ?')
                    ->execute([$p256dh, $auth, $userId, $endpoint]);
            } else {
                $db->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)')
                    ->execute([$userId, $endpoint, $p256dh, $auth]);
            }
        }
    }

    public static function unsubscribe(\PDO $db, int $userId, string $endpoint): void
    {
        $db->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?')
            ->execute([$userId, $endpoint]);
    }

    public static function sendNotification(\PDO $db, int $userId, string $title, string $body, string $url = ''): int
    {
        return PushNotificationService::sendToUser($db, $userId, $title, $body, $url);
    }

    public static function sendBulk(\PDO $db, array $userIds, string $title, string $body, string $url = ''): int
    {
        $sent = 0;
        foreach ($userIds as $userId) {
            $sent += PushNotificationService::sendToUser($db, (int)$userId, $title, $body, $url);
        }
        return $sent;
    }

    public static function getUserSubscriptions(\PDO $db, int $userId): array
    {
        $stmt = $db->prepare('SELECT id, endpoint, created_at FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}
