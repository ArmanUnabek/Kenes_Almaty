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
}

