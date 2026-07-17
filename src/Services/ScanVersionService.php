<?php

namespace App\Services;

class ScanVersionService
{
    public static function uploadNewVersion(\PDO $db, string $letterType, int $letterId, array $scanData, int $userId): int
    {
        $currentStmt = $db->prepare("
            SELECT id, version FROM letter_scans
            WHERE letter_type = ? AND letter_id = ? AND replaced_by IS NULL
            ORDER BY version DESC LIMIT 1
        ");
        $currentStmt->execute([$letterType, $letterId]);
        $current = $currentStmt->fetch(\PDO::FETCH_ASSOC);

        $nextVersion = $current ? (int)$current['version'] + 1 : 1;

        $payload = LetterService::prepareScanPayload($scanData);
        if ($payload['file_path'] === null && $payload['scan_data'] === null) {
            throw new \InvalidArgumentException('Пустые данные скана');
        }

        $insertStmt = $db->prepare("
            INSERT INTO letter_scans (letter_type, letter_id, file_path, scan_data, scan_type, file_name, file_size, version, parent_scan_id, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->execute([
            $letterType,
            $letterId,
            $payload['file_path'],
            $payload['scan_data'],
            $payload['scan_type'],
            $payload['file_name'],
            $payload['file_size'],
            $nextVersion,
            $current ? (int)$current['id'] : null,
            $userId,
        ]);
        $newScanId = (int)$db->lastInsertId();

        if ($current) {
            $db->prepare("UPDATE letter_scans SET replaced_by = ?, replaced_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$newScanId, (int)$current['id']]);
        }

        return $newScanId;
    }

    public static function getVersions(\PDO $db, string $letterType, int $letterId): array
    {
        $stmt = $db->prepare("
            SELECT ls.*,
                   u.full_name AS uploaded_by_name
            FROM letter_scans ls
            LEFT JOIN users u ON ls.uploaded_by = u.id
            WHERE ls.letter_type = ? AND ls.letter_id = ?
            ORDER BY ls.version DESC
        ");
        $stmt->execute([$letterType, $letterId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function restoreVersion(\PDO $db, int $scanId, int $userId): array
    {
        $stmt = $db->prepare("SELECT * FROM letter_scans WHERE id = ?");
        $stmt->execute([$scanId]);
        $target = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$target) {
            throw new \RuntimeException('Скан не найден', 404);
        }

        $letterType = $target['letter_type'];
        $letterId = (int)$target['letter_id'];

        $currentStmt = $db->prepare("
            SELECT id, version FROM letter_scans
            WHERE letter_type = ? AND letter_id = ? AND replaced_by IS NULL
            ORDER BY version DESC LIMIT 1
        ");
        $currentStmt->execute([$letterType, $letterId]);
        $current = $currentStmt->fetch(\PDO::FETCH_ASSOC);

        if ($current && (int)$current['id'] === $scanId) {
            return $target;
        }

        $nextVersion = $current ? (int)$current['version'] + 1 : 1;

        $insertStmt = $db->prepare("
            INSERT INTO letter_scans (letter_type, letter_id, file_path, scan_data, scan_type, file_name, file_size, version, parent_scan_id, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->execute([
            $letterType,
            $letterId,
            $target['file_path'],
            $target['scan_data'],
            $target['scan_type'],
            $target['file_name'],
            $target['file_size'],
            $nextVersion,
            $current ? (int)$current['id'] : null,
            $userId,
        ]);
        $newScanId = (int)$db->lastInsertId();

        if ($current) {
            $db->prepare("UPDATE letter_scans SET replaced_by = ?, replaced_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$newScanId, (int)$current['id']]);
        }

        $db->prepare("UPDATE letter_scans SET replaced_by = ?, replaced_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$newScanId, $scanId]);

        $newScan = $db->prepare("SELECT * FROM letter_scans WHERE id = ?");
        $newScan->execute([$newScanId]);
        return $newScan->fetch(\PDO::FETCH_ASSOC);
    }

    public static function getCurrentVersion(\PDO $db, string $letterType, int $letterId): ?array
    {
        $stmt = $db->prepare("
            SELECT ls.*,
                   u.full_name AS uploaded_by_name
            FROM letter_scans ls
            LEFT JOIN users u ON ls.uploaded_by = u.id
            WHERE ls.letter_type = ? AND ls.letter_id = ? AND ls.replaced_by IS NULL
            ORDER BY ls.version DESC LIMIT 1
        ");
        $stmt->execute([$letterType, $letterId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
