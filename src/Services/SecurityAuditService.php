<?php

namespace App\Services;

use PDO;

/**
 * Аудит операций с риском утечки данных (экспорт, скачивание сканов).
 */
class SecurityAuditService
{
    public const HIGH_VOLUME_THRESHOLD = 50;
    public const EXPORT_RATE_LIMIT = 10;
    public const EXPORT_RATE_WINDOW = 3600;
    public const SCAN_DOWNLOAD_RATE_LIMIT = 60;
    public const SCAN_DOWNLOAD_RATE_WINDOW = 3600;
    public const REGION_EXPORT_RATE_LIMIT = 5;
    public const REGION_EXPORT_RATE_WINDOW = 3600;

    public static function log(
        PDO $db,
        string $operation,
        string $tableName,
        int $recordId,
        ?array $details,
        ?int $userId,
        ?int $regionId = null
    ): void {
        try {
            $details = $details ?? [];
            $details['ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
            $details['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $stmt = $db->prepare('
                INSERT INTO audit_logs (
                    user_id, region_id, table_name, operation, record_id,
                    old_values, new_values, ip_address, user_agent
                ) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)
            ');
            $stmt->execute([
                $userId,
                $regionId,
                $tableName,
                strtoupper($operation),
                $recordId,
                json_encode($details, JSON_ENCODE_FLAGS),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('Security audit log failed: ' . $e->getMessage());
        }
    }

    public static function logExport(
        PDO $db,
        ?int $userId,
        ?int $regionId,
        string $format,
        int $incomingCount,
        int $outgoingCount
    ): void {
        $total = $incomingCount + $outgoingCount;
        $details = [
            'format' => $format,
            'incoming_count' => $incomingCount,
            'outgoing_count' => $outgoingCount,
            'total_records' => $total,
            'high_volume' => $total >= self::HIGH_VOLUME_THRESHOLD,
        ];
        self::log($db, 'EXPORT', 'data_export', $regionId ?? 0, $details, $userId, $regionId);
    }

    public static function logScanDownload(
        PDO $db,
        ?int $userId,
        ?int $regionId,
        int $scanId,
        string $letterType,
        int $letterId,
        string $fileName
    ): void {
        self::log($db, 'DOWNLOAD', 'letter_scans', $scanId, [
            'letter_type' => $letterType,
            'letter_id' => $letterId,
            'file_name' => $fileName,
        ], $userId, $regionId);
    }
}
