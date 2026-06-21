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

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return true; // fail open if we can't create/open the file
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return true; // fail open if we can't acquire lock
        }

        try {
            $content = stream_get_contents($fp);
            $data = json_decode($content ?: '{}', true) ?? [];

            // Очищаем старые записи
            $data['requests'] = array_values(array_filter($data['requests'] ?? [], function ($timestamp) use ($now, $window) {
                return $timestamp > ($now - $window);
            }));

            $requestCount = count($data['requests']);

            if ($requestCount >= $limit) {
                return false;
            }

            // Добавляем новый запрос
            $data['requests'][] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));

            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
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
            ], JSON_ENCODE_FLAGS);
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
