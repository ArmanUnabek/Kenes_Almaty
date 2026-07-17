<?php

namespace App\Middleware;

class RateLimiter
{
    private const CACHE_DIR = __DIR__ . '/../../.rate_limit';
    private const DEFAULT_LIMIT = 100;
    private const DEFAULT_WINDOW = 3600; // 1 час

    /** @var \Redis|null */
    private static mixed $redis = null;
    private static bool $redisChecked = false;

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
            $r->connect($host, $port, 1.5);
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

    /**
     * Returns ['allowed' => bool, 'remaining' => int, 'limit' => int, 'reset_at' => int].
     */
    private static function checkInternal(string $identifier, int $limit, int $window): array
    {
        $redis = self::getRedis();
        if ($redis !== null) {
            return self::checkRedis($redis, $identifier, $limit, $window);
        }
        return self::checkFile($identifier, $limit, $window);
    }

    public static function check(string $identifier, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW): bool
    {
        return self::checkInternal($identifier, $limit, $window)['allowed'];
    }

    private static function checkRedis(\Redis $redis, string $identifier, int $limit, int $window): array
    {
        $key = 'rl:' . hash('sha256', $identifier);
        try {
            $count = (int)$redis->incr($key);
            if ($count === 1) {
                $redis->expire($key, $window);
            }
            $ttl = (int)$redis->ttl($key);
            return [
                'allowed'   => $count <= $limit,
                'remaining' => max(0, $limit - $count),
                'limit'     => $limit,
                'reset_at'  => time() + max(0, $ttl),
            ];
        } catch (\Throwable $e) {
            error_log('RateLimiter Redis check failed, falling back to file: ' . $e->getMessage());
            return self::checkFile($identifier, $limit, $window);
        }
    }

    private static function checkFile(string $identifier, int $limit, int $window): array
    {
        self::init();

        $key  = hash('sha256', $identifier);
        $file = self::CACHE_DIR . '/' . $key . '.json';
        $now  = time();

        $fp = fopen($file, 'c');
        if (!$fp) {
            return ['allowed' => true, 'remaining' => $limit, 'limit' => $limit, 'reset_at' => $now + $window];
        }
        flock($fp, LOCK_EX);

        $data    = [];
        $content = file_get_contents($file);
        if ($content) {
            $data = json_decode($content, true) ?? [];
        }

        // Remove expired entries
        $data['requests'] = array_values(array_filter(
            $data['requests'] ?? [],
            fn($ts) => $ts > ($now - $window)
        ));

        $count    = count($data['requests']);
        $allowed  = $count < $limit;
        $resetAt  = $count > 0 ? ((int)$data['requests'][0] + $window) : ($now + $window);

        if ($allowed) {
            $data['requests'][] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        return [
            'allowed'   => $allowed,
            'remaining' => max(0, $limit - $count - ($allowed ? 1 : 0)),
            'limit'     => $limit,
            'reset_at'  => $resetAt,
        ];
    }

    public static function getIdentifier(): string
    {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId) {
            return "user_$userId";
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    public static function requireCheck(string|int $keyOrLimit = self::DEFAULT_LIMIT, int $limitOrWindow = self::DEFAULT_WINDOW, int $window = 0): void
    {
        if (is_string($keyOrLimit)) {
            $identifier = $keyOrLimit;
            $limit      = $limitOrWindow;
            $window     = $window > 0 ? $window : self::DEFAULT_WINDOW;
        } else {
            $identifier = self::getIdentifier();
            $limit      = $keyOrLimit;
            $window     = $limitOrWindow;
        }

        $info = self::checkInternal($identifier, $limit, $window);

        if (!headers_sent()) {
            header('X-RateLimit-Limit: ' . $info['limit']);
            header('X-RateLimit-Remaining: ' . $info['remaining']);
            header('X-RateLimit-Reset: ' . $info['reset_at']);
        }

        if (!$info['allowed']) {
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
            ], JSON_ENCODE_FLAGS ?? (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            exit;
        }
    }

    public static function cleanup(): void
    {
        self::init();
        $files  = glob(self::CACHE_DIR . '/*.json');
        $now    = time();
        $maxAge = 86400; // 24 часа

        foreach ($files as $file) {
            if (filemtime($file) < ($now - $maxAge)) {
                unlink($file);
            }
        }
    }
}
