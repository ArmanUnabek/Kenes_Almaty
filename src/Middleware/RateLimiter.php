<?php

namespace App\Middleware;

class RateLimiter
{
    private const CACHE_DIR = __DIR__ . '/../../.rate_limit';
    private const DEFAULT_LIMIT = 100;
    private const DEFAULT_WINDOW = 3600; // 1 час

    public static function init(): void
    {
        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }
    }

    public static function check(string $identifier, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW): bool
    {
        self::init();

        $key = hash('sha256', $identifier);
        $file = self::CACHE_DIR . '/' . $key . '.json';
        $now = time();

        $data = [];
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $data = json_decode($content, true) ?? [];
        }

        // Очищаем старые записи
        $data['requests'] = array_filter($data['requests'] ?? [], function ($timestamp) use ($now, $window) {
            return $timestamp > ($now - $window);
        });

        $requestCount = count($data['requests'] ?? []);

        if ($requestCount >= $limit) {
            return false;
        }

        // Добавляем новый запрос
        $data['requests'][] = $now;
        file_put_contents($file, json_encode($data));

        return true;
    }

    public static function getIdentifier(): string
    {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId) {
            return "user_$userId";
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    public static function requireCheck(int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW): void
    {
        if (!self::check(self::getIdentifier(), $limit, $window)) {
            http_response_code(429);
            echo json_encode([
                'error' => 'Слишком много запросов. Попробуйте позже.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public static function cleanup(): void
    {
        self::init();
        $files = glob(self::CACHE_DIR . '/*.json');
        $now = time();
        $maxAge = 86400; // 24 часа

        foreach ($files as $file) {
            if (filemtime($file) < ($now - $maxAge)) {
                unlink($file);
            }
        }
    }
}
