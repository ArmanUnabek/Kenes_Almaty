<?php

namespace App\Services;

class LetterService
{
    public static function getSeqBaseline(string $table, ?\PDO $db = null, ?int $regionId = null): int
    {
        if ($db && $regionId) {
            return RegionService::getSeqBaseline($db, $regionId, $table);
        }
        if ($table === 'incoming_letters') {
            return 1327;
        }
        if ($table === 'outgoing_letters') {
            return 1399;
        }
        return 0;
    }

    public static function computeNextSeq(\PDO $db, string $table, int $regionId): int
    {
        $stmt = $db->prepare("SELECT COALESCE(MAX(seq), 0) AS max_seq FROM {$table} WHERE region_id = ?");
        $stmt->execute([$regionId]);
        $max = (int)$stmt->fetchColumn();
        $baseline = RegionService::getSeqBaseline($db, $regionId, $table);
        return max($max, $baseline) + 1;
    }

    public static function normalizeOutgoingType($type): string
    {
        $allowed = ['gov', 'jt', 'zt', 'recommend', 'other'];
        if (!$type) {
            return 'gov';
        }
        $type = strtolower((string)$type);
        return in_array($type, $allowed, true) ? $type : 'gov';
    }

    public static function assertRegionAccess(array $letter): void
    {
        $regionId = (int)($letter['region_id'] ?? 0);
        if ($regionId > 0 && !canAccessRegion($regionId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Доступ к письму запрещён'], JSON_ENCODE_FLAGS);
            exit;
        }
    }

    /**
     * @return array{file_path:?string,scan_data:?string,scan_type:string,file_name:?string,file_size:?int}
     */
    public static function prepareScanPayload(array $scan): array
    {
        $rawData = (string)($scan['data'] ?? '');
        if (str_starts_with($rawData, 'data:')) {
            $parts = explode(',', $rawData, 2);
            $rawData = $parts[1] ?? '';
        }

        $mime = (string)($scan['type'] ?? 'application/octet-stream');
        $name = $scan['name'] ?? null;
        $size = isset($scan['size']) ? (int)$scan['size'] : null;

        if ($rawData !== '') {
            try {
                $stored = FileStorage::saveBase64Scan($rawData, $mime, $name);
                return [
                    'file_path' => $stored['path'],
                    'scan_data' => null,
                    'scan_type' => $stored['mime'],
                    'file_name' => $stored['file_name'],
                    'file_size' => $stored['size'],
                ];
            } catch (\Throwable $e) {
                $binary = base64_decode($rawData, true);
                if ($binary !== false) {
                    return [
                        'file_path' => null,
                        'scan_data' => $binary,
                        'scan_type' => $mime,
                        'file_name' => $name,
                        'file_size' => $size ?? strlen($binary),
                    ];
                }
            }
        }

        return [
            'file_path' => null,
            'scan_data' => null,
            'scan_type' => $mime,
            'file_name' => $name,
            'file_size' => $size,
        ];
    }

    public static function insertScans(\PDO $db, string $type, int $letterId, array $scans): void
    {
        if (empty($scans)) {
            return;
        }

        $stmt = $db->prepare("
            INSERT INTO letter_scans (letter_type, letter_id, file_path, scan_data, scan_type, file_name, file_size)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($scans as $scan) {
            if (!is_array($scan)) {
                continue;
            }
            $payload = self::prepareScanPayload($scan);
            if ($payload['file_path'] === null && $payload['scan_data'] === null) {
                continue;
            }
            $stmt->execute([
                $type,
                $letterId,
                $payload['file_path'],
                $payload['scan_data'],
                $payload['scan_type'],
                $payload['file_name'],
                $payload['file_size'],
            ]);
        }
    }

    public static function validateIncoming(array $data): array
    {
        $validator = new \App\Validator();
        $rules = [
            'date' => 'date',
            'organization' => 'string|max:255',
            'subject' => 'string|max:500',
            'note' => 'string|max:2000',
            'category' => 'in:KK,N,JT,ZT',
        ];
        if (!$validator->validate($data, $rules)) {
            throw new \InvalidArgumentException($validator->getFirstError() ?: 'Ошибка валидации');
        }
        return $data;
    }

    public static function validateOutgoing(array $data): array
    {
        $validator = new \App\Validator();
        $rules = [
            'date' => 'date',
            'organization' => 'string|max:255',
            'subject' => 'string|max:500',
            'note' => 'string|max:2000',
            'outgoing_type' => 'in:gov,jt,zt,recommend,other',
        ];
        if (!$validator->validate($data, $rules)) {
            throw new \InvalidArgumentException($validator->getFirstError() ?: 'Ошибка валидации');
        }
        return $data;
    }

    public static function validateEvent(array $data): array
    {
        $validator = new \App\Validator();
        $rules = [
            'title' => 'required|string|min:2|max:255',
            'event_date' => 'required|date',
            'location' => 'string|max:255',
            'notes' => 'string|max:2000',
        ];
        if (!$validator->validate($data, $rules)) {
            throw new \InvalidArgumentException($validator->getFirstError() ?: 'Ошибка валидации');
        }
        return $data;
    }
}
