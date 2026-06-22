<?php

namespace App\Services;

use App\Services\TelegramService;

class LetterPersistenceService
{
    public static function ensureRecipientsSupport(\PDO $db): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        try {
            $db->query('SELECT 1 FROM letter_recipients LIMIT 1');
            $checked = true;
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), "doesn't exist") !== false) {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS letter_recipients (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        letter_type ENUM('incoming','outgoing') NOT NULL,
                        letter_id INT NOT NULL,
                        recipient VARCHAR(255) NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_letter (letter_type, letter_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                $checked = true;
            } else {
                throw $e;
            }
        }
    }

    public static function ensureIncomingCategorySupport(\PDO $db): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql') {
            $checked = true;
            return;
        }
        try {
            $stmt = $db->query("SHOW COLUMNS FROM incoming_letters LIKE 'category'");
            $column = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($column && stripos($column['Type'], "'JT'") === false) {
                $db->exec("ALTER TABLE incoming_letters MODIFY category ENUM('KK','N','JT','ZT') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'KK'");
                $db->exec("UPDATE incoming_letters SET category='JT' WHERE category='ZT'");
            }
        } catch (\PDOException $e) {
            error_log('Failed to ensure incoming category support: ' . $e->getMessage());
        }
        $checked = true;
    }

    public static function normalizeMembersPayload($raw): array
    {
        if (empty($raw) || !is_array($raw)) {
            return [];
        }
        $normalized = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $memberId = $item['member_id'] ?? null;
                if ($memberId) {
                    $normalized[] = [
                        'member_id' => (int)$memberId,
                        'is_lead' => !empty($item['is_lead']),
                    ];
                }
            } elseif (is_numeric($item)) {
                $normalized[] = [
                    'member_id' => (int)$item,
                    'is_lead' => false,
                ];
            }
        }
        return $normalized;
    }

    public static function normalizeRecipientsPayload($raw): array
    {
        if (empty($raw) || !is_array($raw)) {
            return [];
        }
        $normalized = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $name = trim($item['recipient'] ?? $item['full_name'] ?? '');
            } else {
                $name = trim((string)$item);
            }
            if ($name !== '') {
                $normalized[] = $name;
            }
        }
        return $normalized;
    }

    public static function buildReplyNumber(\PDO $db, int $seq, int $outgoingId): ?string
    {
        $stmt = $db->prepare('SELECT outgoing_number FROM outgoing_letters WHERE id = ?');
        $stmt->execute([$outgoingId]);
        $outNumber = $stmt->fetchColumn();
        if (!$outNumber) {
            return null;
        }
        return $seq . '/' . $outNumber;
    }

    public static function syncLetterMembers(\PDO $db, string $type, int $letterId, array $members): void
    {
        // Capture existing member IDs before clearing so we can detect new assignments
        $stmtExisting = $db->prepare('SELECT member_id FROM letter_members WHERE letter_type = ? AND letter_id = ?');
        $stmtExisting->execute([$type, $letterId]);
        $existingIds = array_map('intval', $stmtExisting->fetchAll(\PDO::FETCH_COLUMN));

        $stmtDelete = $db->prepare('DELETE FROM letter_members WHERE letter_type = ? AND letter_id = ?');
        $stmtDelete->execute([$type, $letterId]);
        if (empty($members)) {
            return;
        }
        $stmtInsert = $db->prepare('
            INSERT INTO letter_members (letter_type, letter_id, member_id, is_lead)
            VALUES (?, ?, ?, ?)
        ');
        // Регион письма постоянен в пределах вызова — читаем один раз, а не на каждого участника.
        $letterRegion = self::getLetterRegionId($db, $type, $letterId);
        $check = $db->prepare('SELECT region_id FROM os_members WHERE id = ?');
        $insertedIds = [];
        foreach ($members as $member) {
            $memberId = (int)($member['member_id'] ?? 0);
            if ($memberId <= 0) {
                continue;
            }
            $check->execute([$memberId]);
            $memberRegion = (int)$check->fetchColumn();
            if ($letterRegion > 0 && $memberRegion > 0 && $memberRegion !== $letterRegion) {
                continue;
            }
            $stmtInsert->execute([
                $type,
                $letterId,
                $memberId,
                !empty($member['is_lead']) ? 1 : 0,
            ]);
            $insertedIds[] = $memberId;
        }

        // Notify members who are newly assigned (not in previous set)
        $newlyAssigned = array_diff($insertedIds, $existingIds);
        if (!empty($newlyAssigned)) {
            try {
                // Fetch letter seq and organization for the notification message
                $table = $type === 'incoming' ? 'incoming_letters' : 'outgoing_letters';
                $orgCol = $type === 'incoming' ? 'organization' : 'recipient';
                $stmtLetter = $db->prepare("SELECT seq, {$orgCol} AS org FROM {$table} WHERE id = ?");
                $stmtLetter->execute([$letterId]);
                $letter = $stmtLetter->fetch(\PDO::FETCH_ASSOC);
                if ($letter) {
                    TelegramService::notifyLetterAssignment(
                        $db,
                        array_values($newlyAssigned),
                        $type,
                        (int)$letter['seq'],
                        (string)($letter['org'] ?? '')
                    );
                }
            } catch (\Throwable $e) {
                error_log('syncLetterMembers: Telegram notify failed: ' . $e->getMessage());
            }
        }
    }

    private static function getLetterRegionId(\PDO $db, string $type, int $letterId): int
    {
        $table = $type === 'incoming' ? 'incoming_letters' : 'outgoing_letters';
        $stmt = $db->prepare("SELECT region_id FROM {$table} WHERE id = ?");
        $stmt->execute([$letterId]);
        return (int)$stmt->fetchColumn();
    }

    public static function fetchLetterMembers(\PDO $db, string $type, array $letterIds): array
    {
        if (empty($letterIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($letterIds), '?'));
        $stmt = $db->prepare("
            SELECT lm.*, m.full_name, m.position, m.organization
            FROM letter_members lm
            JOIN os_members m ON lm.member_id = m.id
            WHERE lm.letter_type = ? AND lm.letter_id IN ($placeholders)
            ORDER BY lm.is_lead DESC, m.full_name ASC
        ");
        $stmt->execute(array_merge([$type], $letterIds));
        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[$row['letter_id']][] = $row;
        }
        return $grouped;
    }

    public static function syncLetterRecipients(\PDO $db, string $type, int $letterId, array $recipients): void
    {
        self::ensureRecipientsSupport($db);
        $db->prepare('DELETE FROM letter_recipients WHERE letter_type = ? AND letter_id = ?')
            ->execute([$type, $letterId]);
        if (empty($recipients)) {
            return;
        }
        $stmt = $db->prepare('INSERT INTO letter_recipients (letter_type, letter_id, recipient) VALUES (?, ?, ?)');
        foreach ($recipients as $name) {
            $stmt->execute([$type, $letterId, $name]);
        }
    }

    public static function fetchLetterRecipients(\PDO $db, string $type, array $letterIds): array
    {
        if (empty($letterIds)) {
            return [];
        }
        self::ensureRecipientsSupport($db);
        $placeholders = implode(',', array_fill(0, count($letterIds), '?'));
        $stmt = $db->prepare("SELECT letter_id, recipient FROM letter_recipients WHERE letter_type = ? AND letter_id IN ($placeholders) ORDER BY id");
        $stmt->execute(array_merge([$type], $letterIds));
        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[$row['letter_id']][] = $row['recipient'];
        }
        return $grouped;
    }

    public static function attachScansToLetter(array $letter, string $type, int $id, \PDO $db): array
    {
        $stmt = $db->prepare("
            SELECT id, letter_type, letter_id, file_path, scan_type, file_name, file_size, created_at,
                   CASE WHEN file_path IS NOT NULL THEN NULL ELSE scan_data END AS scan_data
            FROM letter_scans
            WHERE letter_type = ? AND letter_id = ?
            ORDER BY created_at
        ");
        $stmt->execute([$type, $id]);
        $letter['scans'] = array_map(static function (array $scan): array {
            if (!empty($scan['file_path'])) {
                $scan['scan_url'] = '/api/scan_download.php?id=' . (int)$scan['id'] . '&inline=1';
            }
            return $scan;
        }, $stmt->fetchAll());
        return $letter;
    }
}
