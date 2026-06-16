<?php
/**
 * Middleware для проверки аутентификации
 */

namespace App\Middleware;

class AuthMiddleware
{
    /**
     * Требовать аутентификацию
     *
     * @return array Данные текущего пользователя
     * @throws \Exception Если пользователь не аутентифицирован
     */
    public static function requireAuth(): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            exit(json_encode(['error' => 'Требуется аутентификация']));
        }

        return [
            'user_id' => $_SESSION['user_id'],
            'user_role' => $_SESSION['user_role'] ?? 'user',
            'region_id' => $_SESSION['region_id'] ?? null,
        ];
    }

    /**
     * Требовать роль
     *
     * @param string|array $roles Допустимые роли
     * @return array Данные текущего пользователя
     */
    public static function requireRole($roles): array
    {
        $user = self::requireAuth();
        $rolesArray = is_array($roles) ? $roles : [$roles];

        if (!in_array($user['user_role'], $rolesArray, true)) {
            http_response_code(403);
            exit(json_encode(['error' => 'У вас нет прав доступа']));
        }

        return $user;
    }

    /**
     * Получить текущего пользователя (если аутентифицирован)
     *
     * @return array|null Данные пользователя или null
     */
    public static function getCurrentUser(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        return [
            'user_id' => $_SESSION['user_id'],
            'user_role' => $_SESSION['user_role'] ?? 'user',
            'region_id' => $_SESSION['region_id'] ?? null,
        ];
    }
}
