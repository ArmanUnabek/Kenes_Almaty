<?php

namespace App\Middleware;

class RateLimiter
{
    private const CACHE_DIR = __DIR__ . '/../../.rate_limit';
    private const DEFAULT_LIMIT = 100;
    private const DEFAULT_WINDOW = 3600; // 1 час

    /** @var \Redis|\RedisException|null */
    private static mixed $redis = null;
    private static bool $redisChecked = false;

    /**
     * Returns a Redis connection if REDIS_HOST is configured and the Redis extension is loaded,
     * otherwise returns null (file-based fallback is used).
     */
    private static function getRedis(): ?\Redis
    {
        if (self::$redisChecked) {
            return self::$redis instanceof \Redis ? self::$redis : null;
        }
        self::$redisChecked = true;

        $host = function_exists('envValue') ? (envValue('REDIS_HOST') ?? '') : (getenv('REDIS_HOST') ?: '');
        if ($host === '' || !class_exists(\Redis::class)) {
            return null;
        }

        try {
            $port = (int)(function_exists('envValue') ? (envValue('REDIS_PORT') ?? '6379') : (getenv('REDIS_PORT') ?: '6379'));
            $r = new \Redis();
            $r->connect($host, $port, 1.5); // 1.5s timeout
            $pass = function_exists('envValue') ? (envValue('REDIS_PASSWORD') ?? '') : (getenv('REDIS_PASSWORD') ?: '');
            if ($pass !== '') {
                $r->auth($pass);
            }
            self::$redis = $r;
        } catch (\Throwable $e) {
            error_log('RateLimiter: Redis connect failed: ' . $e->getMessage());
            self::$redis = null;
        }

        return self::$redis instanceof \Redis ? self::$redis : null;
    }

    public static function init(): void
    {
        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }
    }

    public static function check(string $identifier, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW): bool
    {
        $redis = self::getRedis();
        if ($redis !== null) {
            return self::checkRedis($redis, $identifier, $limit, $window);
        }
        return self::checkFile($identifier, $limit, $window);
    }

    private static function checkRedis(\Redis $redis, string $identifier, int $limit, int $window): bool
    {
        $key = 'rl:' . hash('sha256', $identifier);
        try {
            $count = (int)$redis->incr($key);
            if ($count === 1) {
                $redis->expire($key, $window);
            }
            return $count <= $limit;
        } catch (\Throwable $e) {
            error_log('RateLimiter Redis check failed, falling back to file: ' . $e->getMessage());
            return self::checkFile($identifier, $limit, $window);
        }
    }

    private static function checkFile(string $identifier, int $limit, int $window): bool
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

        // Remove expired entries
        $data['requests'] = array_filter($data['requests'] ?? [], function ($timestamp) use ($now, $window) {
            return $timestamp > ($now - $window);
        });

        $requestCount = count($data['requests'] ?? []);

        if ($requestCount >= $limit) {
            return false;
        }

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
        $identifier = self::getIdentifier();
        if (!self::check($identifier, $limit, $window)) {
            try {
                if (function_exists('getDBConnection')) {
                    $db = getDBConnection();
                    \App\Services\SecurityAuditService::log(
                        $db, 'RATE_LIMIT', 'security_events', 0,
                        [
                            'identifier' => $identifier,
                            'limit'      => $limit,
                            'window_sec' => $window,
                            'uri'        => $_SERVER['REQUEST_URI'] ?? '',
                        ],
                        isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
                    );
                }
            } catch (\Throwable $e) {}

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
