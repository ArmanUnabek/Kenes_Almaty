<?php

namespace App\Services;

use PDO;

class AuditLogger
{
    public static function log(
        PDO $db,
        string $tableName,
        int $recordId,
        string $action,
        ?array $oldValue,
        ?array $newValue,
        ?int $userId
    ): void {
        try {
            $stmt = $db->prepare("
                INSERT INTO audit_logs (
                    table_name, record_id, operation, old_values, new_values, user_id, ip_address, user_agent
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $tableName,
                $recordId,
                strtoupper($action),
                $oldValue ? json_encode($oldValue, JSON_ENCODE_FLAGS) : null,
                $newValue ? json_encode($newValue, JSON_ENCODE_FLAGS) : null,
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('Audit log write failed: ' . $e->getMessage());
        }
    }
}

