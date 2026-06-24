<?php

namespace App\Services;

class FileStorage
{
    private const MAX_SCAN_SIZE_BYTES = 10485760; // 10 MB
    private const ALLOWED_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    public static function saveBase64Scan(string $base64Data, string $mimeType, ?string $originalName = null): array
    {
        if (!isset(self::ALLOWED_MIME[$mimeType])) {
            throw new \RuntimeException('Недопустимый тип файла');
        }
        $binary = base64_decode($base64Data, true);
        if ($binary === false) {
            throw new \RuntimeException('Некорректный base64');
        }
        if (strlen($binary) > self::MAX_SCAN_SIZE_BYTES) {
            throw new \RuntimeException('Файл превышает лимит 10MB');
        }

        $dir = APP_ROOT . '/uploads/scans/' . date('Y/m');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать директорию загрузки');
        }

        $ext = self::ALLOWED_MIME[$mimeType];
        $name = bin2hex(random_bytes(16)) . '_' . time() . '.' . $ext;
        $path = $dir . '/' . $name;
        if (@file_put_contents($path, $binary) === false) {
            throw new \RuntimeException('Ошибка записи файла');
        }

        return [
            'path' => str_replace(APP_ROOT . '/', '', $path),
            'mime' => $mimeType,
            'file_name' => $originalName ?: $name,
            'size' => strlen($binary),
        ];
    }

    /**
     * Confine a filesystem path to a base directory using purely lexical
     * normalization (no filesystem access). Returns the normalized path if it
     * stays within $baseDir, or null if it escapes via `..` / absolute jumps.
     *
     * Defends against path traversal (CWE-22) when a path originates from
     * storage or user input — e.g. before unlink()ing a member's old photo
     * whose path comes from the database.
     */
    public static function pathWithinBase(string $baseDir, string $path): ?string
    {
        $base = self::normalizeLexicalPath($baseDir);
        $norm = self::normalizeLexicalPath($path);
        if ($base === '' || $norm === '') {
            return null;
        }
        if ($norm === $base || str_starts_with($norm, $base . '/')) {
            return $norm;
        }
        return null;
    }

    /**
     * Lexically resolve `.`/`..` segments and normalize separators without
     * touching the filesystem (deterministic, unit-testable).
     */
    private static function normalizeLexicalPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $isAbsolute = isset($path[0]) && $path[0] === '/';
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return ($isAbsolute ? '/' : '') . implode('/', $out);
    }
}

