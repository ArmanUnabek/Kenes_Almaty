<?php

namespace App\Services;

/**
 * Политика получателей email-уведомлений.
 * Admin — любой адрес; moderator — только свой регион или разрешённые домены.
 */
class NotificationRecipientPolicy
{
    public static function assertAllowed(\PDO $db, string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::deny('Некорректный email');
        }

        if (isAdmin()) {
            return;
        }

        $user = getCurrentUser();
        if (!$user) {
            self::deny('Требуется авторизация', 401);
        }

        $regionId = (int)($user['region_id'] ?? 0);
        if ($regionId <= 0) {
            self::deny('У пользователя не назначен регион');
        }

        if (self::isEmailInRegion($db, $email, $regionId)) {
            return;
        }

        if (self::isDomainAllowed($email)) {
            return;
        }

        self::deny(
            'Модератор может отправлять только на email пользователей/членов ОС своего региона '
            . 'или на адреса из NOTIFY_ALLOWED_DOMAINS'
        );
    }

    public static function isDomainAllowed(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = strtolower(substr($email, $at + 1));
        if ($domain === '') {
            return false;
        }

        $raw = getenv('NOTIFY_ALLOWED_DOMAINS') ?: '';
        if ($raw === '') {
            return false;
        }

        $allowed = array_filter(array_map(static function ($part) {
            return strtolower(trim($part));
        }, explode(',', $raw)));

        return in_array($domain, $allowed, true);
    }

    public static function isEmailInRegion(\PDO $db, string $email, int $regionId): bool
    {
        $stmt = $db->prepare('
            SELECT 1 FROM users
            WHERE region_id = ? AND LOWER(email) = ? AND is_active = TRUE
            LIMIT 1
        ');
        $stmt->execute([$regionId, $email]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        $stmt = $db->prepare('
            SELECT 1 FROM os_members
            WHERE region_id = ? AND status = \'active\' AND email IS NOT NULL AND LOWER(email) = ?
            LIMIT 1
        ');
        $stmt->execute([$regionId, $email]);
        return (bool)$stmt->fetchColumn();
    }

    private static function deny(string $message, int $status = 403): void
    {
        http_response_code($status);
        echo json_encode(['error' => $message], JSON_ENCODE_FLAGS);
        exit;
    }
}
