<?php

namespace App\Middleware;

class CsrfMiddleware
{
    private const TOKEN_LENGTH = 32;
    private const TOKEN_NAME = '_csrf_token';
    private const HEADER_NAME = 'X-CSRF-Token';

    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION[self::TOKEN_NAME])) {
            $_SESSION[self::TOKEN_NAME] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        }
    }

    public static function getToken(): string
    {
        self::init();
        return $_SESSION[self::TOKEN_NAME] ?? '';
    }

    public static function verify(): bool
    {
        self::init();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        // GET и OPTIONS запросы не требуют CSRF токена
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $token = $_POST[self::TOKEN_NAME] 
            ?? $_SERVER['HTTP_' . str_replace('-', '_', strtoupper(self::HEADER_NAME))] 
            ?? null;

        if (!$token) {
            return false;
        }

        $sessionToken = $_SESSION[self::TOKEN_NAME] ?? null;
        
        if (!$sessionToken || !hash_equals($sessionToken, $token)) {
            return false;
        }

        // Токен остаётся стабильным в пределах сессии: фронтенд (csrf-handler.js)
        // кэширует его на время жизни страницы и переиспользует для всех мутаций.
        return true;
    }

    public static function requireVerification(): void
    {
        if (!self::verify()) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF токен невалиден'], JSON_ENCODE_FLAGS);
            exit;
        }
    }
}
