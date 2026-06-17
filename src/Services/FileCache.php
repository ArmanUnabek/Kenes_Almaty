<?php

namespace App\Services;

class FileCache
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?: APP_ROOT . '/cache/data';
        if (!is_dir($this->basePath)) {
            @mkdir($this->basePath, 0775, true);
        }
    }

    public function get(string $key)
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return null;
        }
        if (($payload['expires_at'] ?? 0) < time()) {
            @unlink($path);
            return null;
        }
        return $payload['value'] ?? null;
    }

    public function set(string $key, $value, int $ttlSeconds): void
    {
        $payload = [
            'key' => $key,
            'expires_at' => time() + max(1, $ttlSeconds),
            'value' => $value,
        ];
        @file_put_contents($this->pathFor($key), json_encode($payload, JSON_ENCODE_FLAGS));
    }

    public function forget(string $key): void
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function forgetPrefix(string $prefix): void
    {
        foreach (glob($this->basePath . '/*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                continue;
            }
            $key = (string)($payload['key'] ?? '');
            if ($key !== '' && str_starts_with($key, $prefix)) {
                @unlink($file);
            }
        }
    }

    public function flushAll(): void
    {
        foreach (glob($this->basePath . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function pathFor(string $key): string
    {
        return $this->basePath . '/' . md5($key) . '.json';
    }
}
