<?php

namespace App\Services;

/**
 * Удаляет чувствительные данные перед записью в audit_logs.
 */
class AuditSanitizer
{
    private const REDACTED_KEYS = ['password', 'password_hash', 'password_confirm', 'token', 'csrf_token'];

    public static function sanitize(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $copy = $data;
        foreach (self::REDACTED_KEYS as $key) {
            if (array_key_exists($key, $copy)) {
                $copy[$key] = '[REDACTED]';
            }
        }

        if (isset($copy['scans']) && is_array($copy['scans'])) {
            $copy['scans'] = array_map(static function ($scan) {
                if (!is_array($scan)) {
                    return $scan;
                }
                $payload = (string)($scan['data'] ?? '');
                return [
                    'name' => $scan['name'] ?? $scan['file_name'] ?? null,
                    'type' => $scan['type'] ?? $scan['scan_type'] ?? null,
                    'bytes' => $payload !== '' ? strlen($payload) : ($scan['file_size'] ?? null),
                ];
            }, $copy['scans']);
        }

        return $copy;
    }
}
