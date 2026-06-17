<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Middleware\CsrfMiddleware;
use App\Services\AuditLogger;
use App\Services\LetterService;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$JSON_FLAGS = JSON_ENCODE_FLAGS;

function ensureRecipientsSupport(PDO $db) {
    static $checked = false;
    if ($checked) {
        return;
    }
    try {
        $db->query("SELECT 1 FROM letter_recipients LIMIT 1");
        $checked = true;
    } catch (PDOException $e) {
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

function ensureIncomingCategorySupport(PDO $db) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver !== 'mysql') {
        $checked = true;
        return;
    }
    try {
        $stmt = $db->query("SHOW COLUMNS FROM incoming_letters LIKE 'category'");
        $column = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($column && stripos($column['Type'], "'JT'") === false) {
            $db->exec("ALTER TABLE incoming_letters MODIFY category ENUM('KK','N','JT','ZT') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'KK'");
            $db->exec("UPDATE incoming_letters SET category='JT' WHERE category='ZT'");
        }
    } catch (PDOException $e) {
        error_log('Failed to ensure incoming category support: ' . $e->getMessage());
    }
    $checked = true;
}

function normalizeOutgoingType($type) {
    $allowed = ['gov','jt','zt','recommend','other'];
    if (!$type) {
        return 'gov';
    }
    $type = strtolower((string)$type);
    return in_array($type, $allowed, true) ? $type : 'gov';
}

function normalizeMembersPayload($raw) {
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

function normalizeRecipientsPayload($raw) {
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

function buildReplyNumber(PDO $db, int $seq, int $outgoingId) {
    $stmt = $db->prepare("SELECT outgoing_number FROM outgoing_letters WHERE id = ?");
    $stmt->execute([$outgoingId]);
    $outNumber = $stmt->fetchColumn();
    if (!$outNumber) {
        return null;
    }
    return $seq . '/' . $outNumber;
}

function syncLetterMembers(PDO $db, string $type, int $letterId, array $members) {
    $stmtDelete = $db->prepare("DELETE FROM letter_members WHERE letter_type = ? AND letter_id = ?");
    $stmtDelete->execute([$type, $letterId]);

    if (empty($members)) {
        return;
    }

    $stmtInsert = $db->prepare("
        INSERT INTO letter_members (letter_type, letter_id, member_id, is_lead)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($members as $member) {
        $stmtInsert->execute([
            $type,
            $letterId,
            $member['member_id'],
            !empty($member['is_lead']) ? 1 : 0
        ]);
    }
}

function fetchLetterMembers(PDO $db, string $type, array $letterIds) {
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
    $rows = $stmt->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['letter_id']][] = $row;
    }
    return $grouped;
}

function syncLetterRecipients(PDO $db, string $type, int $letterId, array $recipients) {
    ensureRecipientsSupport($db);
    $db->prepare("DELETE FROM letter_recipients WHERE letter_type = ? AND letter_id = ?")->execute([$type, $letterId]);
    if (empty($recipients)) {
        return;
    }
    $stmt = $db->prepare("INSERT INTO letter_recipients (letter_type, letter_id, recipient) VALUES (?, ?, ?)");
    foreach ($recipients as $name) {
        $stmt->execute([$type, $letterId, $name]);
    }
}

function fetchLetterRecipients(PDO $db, string $type, array $letterIds) {
    if (empty($letterIds)) {
        return [];
    }
    ensureRecipientsSupport($db);
    $placeholders = implode(',', array_fill(0, count($letterIds), '?'));
    $stmt = $db->prepare("SELECT letter_id, recipient FROM letter_recipients WHERE letter_type = ? AND letter_id IN ($placeholders) ORDER BY id");
    $stmt->execute(array_merge([$type], $letterIds));
    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[$row['letter_id']][] = $row['recipient'];
    }
    return $grouped;
}

// Доступ к письмам только для авторизованных пользователей (для всех методов).
checkAuth();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDBConnection();
ensureIncomingCategorySupport($db);

$type = $_GET['type'] ?? 'incoming'; // incoming или outgoing
$table = $type === 'incoming' ? 'incoming_letters' : 'outgoing_letters';

switch ($method) {
    case 'GET':
        $id = $_GET['id'] ?? null;

        if ($id) {
            $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ?");
            $stmt->execute([$id]);
            $letter = $stmt->fetch();

            if ($letter) {
                LetterService::assertRegionAccess($letter);
                // Получить сканы
                $stmt2 = $db->prepare("
                    SELECT id, letter_type, letter_id, file_path, scan_type, file_name, file_size, created_at,
                           CASE WHEN file_path IS NOT NULL THEN NULL ELSE scan_data END AS scan_data
                    FROM letter_scans 
                    WHERE letter_type = ? AND letter_id = ?
                    ORDER BY created_at
                ");
                $stmt2->execute([$type, $id]);
                $letter['scans'] = array_map(static function (array $scan): array {
                    if (!empty($scan['file_path'])) {
                        $scan['scan_url'] = '/api/scan_download.php?id=' . (int)$scan['id'] . '&inline=1';
                    }
                    return $scan;
                }, $stmt2->fetchAll());

                $members = fetchLetterMembers($db, $type, [$letter['id']]);
                $letter['members'] = $members[$letter['id']] ?? [];
                $recipients = fetchLetterRecipients($db, $type, [$letter['id']]);
                $letter['recipients'] = $recipients[$letter['id']] ?? [];

                echo json_encode($letter, $JSON_FLAGS);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Письмо не найдено'], $JSON_FLAGS);
            }
        } else {
            // Получить все письма (с учетом региона пользователя, если он задан)
            $currentRegionId = getCurrentRegionId();

            // Пагинация опциональна: применяется только если передан limit.
            // LIMIT/OFFSET биндятся как целые (PDO::PARAM_INT), т.к. EMULATE_PREPARES=false.
            $hasPagination = isset($_GET['limit']);
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 50)));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $offset = ($page - 1) * $limit;

            if ($currentRegionId) {
                $sql = "SELECT * FROM {$table} WHERE region_id = ? ORDER BY date DESC, seq DESC";
                if ($hasPagination) {
                    $sql .= " LIMIT ? OFFSET ?";
                }
                $stmt = $db->prepare($sql);
                $stmt->bindValue(1, (int)$currentRegionId, PDO::PARAM_INT);
                if ($hasPagination) {
                    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
                    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
                }
                $stmt->execute();
                $letters = $stmt->fetchAll();
            } else {
                // Админ без region_id увидит все
                $sql = "SELECT * FROM {$table} ORDER BY date DESC, seq DESC";
                if ($hasPagination) {
                    $sql .= " LIMIT ? OFFSET ?";
                }
                $stmt = $db->prepare($sql);
                if ($hasPagination) {
                    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
                    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
                }
                $stmt->execute();
                $letters = $stmt->fetchAll();
            }

            // Добавить сканы для каждого письма
            foreach ($letters as &$letter) {
                $stmt2 = $db->prepare("
                    SELECT COUNT(*) as count FROM letter_scans 
                    WHERE letter_type = ? AND letter_id = ?
                ");
                $stmt2->execute([$type, $letter['id']]);
                $scanCount = $stmt2->fetch();
                $letter['scans_count'] = $scanCount['count'];
            }
            unset($letter);

            // Добавить ответственных членов
            $letterIds = array_column($letters, 'id');
            $members = fetchLetterMembers($db, $type, $letterIds);
            foreach ($letters as &$letter) {
                $letter['members'] = $members[$letter['id']] ?? [];
            }
            unset($letter);
            $recipients = fetchLetterRecipients($db, $type, $letterIds);
            foreach ($letters as &$letter) {
                $letter['recipients'] = $recipients[$letter['id']] ?? [];
            }
            unset($letter);

            echo json_encode($letters, $JSON_FLAGS);
        }
        break;

    case 'POST':
        requireWriteAccess();
        CsrfMiddleware::requireVerification();
        $data = json_decode(file_get_contents('php://input'), true);

        try {
            if ($type === 'incoming') {
                LetterService::validateIncoming($data ?? []);
            } else {
                LetterService::validateOutgoing($data ?? []);
            }
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()], $JSON_FLAGS);
            break;
        }

        $currentUser = getCurrentUser();
        $regionId = $data['region_id'] ?? ($currentUser['region_id'] ?? 1); // по умолчанию регион 1 (Алматы)
        $createdBy = $currentUser['id'] ?? null;
        $members = normalizeMembersPayload($data['members'] ?? []);
        $recipients = normalizeRecipientsPayload($data['recipients'] ?? []);
        $respondsToOutgoingId = !empty($data['responds_to_outgoing_id']) ? (int)$data['responds_to_outgoing_id'] : null;

        try {
            $db->beginTransaction();

            if ($type === 'incoming') {
                $seq = $data['seq'] ?? null;
                if (!$seq) {
                    $seq = LetterService::computeNextSeq($db, $table, $regionId);
                }
                $kkNumber = $data['kk_number'] ?? '';
                if ($respondsToOutgoingId && !$kkNumber) {
                    $generatedNumber = buildReplyNumber($db, (int)$seq, $respondsToOutgoingId);
                    if ($generatedNumber) {
                        $kkNumber = $generatedNumber;
                    }
                }
                $stmt = $db->prepare("
                    INSERT INTO incoming_letters 
                    (region_id, seq, date, organization, kk_number, category, subject, note, responds_to_outgoing_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $regionId,
                    $seq,
                    $data['date'] ?? date('Y-m-d'),
                    $data['organization'] ?? '',
                    $kkNumber,
                    $data['category'] ?? 'KK',
                    $data['subject'] ?? null,
                    $data['note'] ?? null,
                    $respondsToOutgoingId,
                    $createdBy
                ]);
            } else {
                $seq = $data['seq'] ?? null;
                if (!$seq) {
                    $seq = LetterService::computeNextSeq($db, $table, $regionId);
                }
                $outgoingNumber = $data['outgoing_number'] ?? '';
                $outgoingType = normalizeOutgoingType($data['outgoing_type'] ?? null);
                if (!$outgoingNumber && !empty($data['incoming_ref_id'])) {
                    $stmtIncoming = $db->prepare("SELECT kk_number FROM incoming_letters WHERE id = ?");
                    $stmtIncoming->execute([$data['incoming_ref_id']]);
                    $kk = $stmtIncoming->fetchColumn();
                    $outgoingNumber = $kk ? "{$seq}/{$kk}" : (string)$seq;
                }
                if (!$outgoingNumber) {
                    $outgoingNumber = (string)$seq;
                }
                $stmt = $db->prepare("
                    INSERT INTO outgoing_letters 
                    (region_id, seq, date, outgoing_number, organization, incoming_ref_id, outgoing_type, subject, note, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $regionId,
                    $seq,
                    $data['date'] ?? date('Y-m-d'),
                    $outgoingNumber,
                    $data['organization'] ?? '',
                    $data['incoming_ref_id'] ?? null,
                    $outgoingType,
                    $data['subject'] ?? null,
                    $data['note'] ?? null,
                    $createdBy
                ]);
            }

            $letter_id = $db->lastInsertId();

            if ($type === 'outgoing' && !empty($data['incoming_ref_id'])) {
                $stmtLink = $db->prepare("UPDATE incoming_letters SET linked_outgoing_id = ? WHERE id = ?");
                $stmtLink->execute([$letter_id, $data['incoming_ref_id']]);
            }

            syncLetterMembers($db, $type, (int)$letter_id, $members);
            syncLetterRecipients($db, $type, (int)$letter_id, $recipients);

            // Сохранить сканы, если есть
            if (!empty($data['scans'])) {
                LetterService::insertScans($db, $type, (int)$letter_id, $data['scans']);
            }

            AuditLogger::log($db, $table, (int)$letter_id, 'CREATE', null, $data, (int)($_SESSION['user_id'] ?? 0) ?: null);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            error_log('letters create failed: ' . $e->getMessage());
            echo json_encode(['error' => 'Внутренняя ошибка сервера'], $JSON_FLAGS);
            break;
        }

        pusherTrigger('council-documents', 'documents-updated', [
            'action' => 'create',
            'type' => $type,
            'id' => (int)$letter_id,
            'region_id' => (int)$regionId
        ]);
        echo json_encode(['id' => $letter_id, 'message' => 'Письмо успешно добавлено'], $JSON_FLAGS);
        break;

    case 'PUT':
        requireWriteAccess();
        CsrfMiddleware::requireVerification();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? ($_GET['id'] ?? null);

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID не указан'], $JSON_FLAGS);
            break;
        }

        try {
            if ($type === 'incoming') {
                LetterService::validateIncoming($data ?? []);
            } else {
                LetterService::validateOutgoing($data ?? []);
            }
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()], $JSON_FLAGS);
            break;
        }

        $table = $type === 'incoming' ? 'incoming_letters' : 'outgoing_letters';
        // incoming_ref_id есть только в outgoing_letters
        $refColumn = $type === 'outgoing' ? ', incoming_ref_id' : '';
        $stmtLetter = $db->prepare("SELECT region_id{$refColumn} FROM {$table} WHERE id = ?");
        $stmtLetter->execute([$id]);
        $existingRow = $stmtLetter->fetch();
        if (!$existingRow) {
            http_response_code(404);
            echo json_encode(['error' => 'Письмо не найдено'], $JSON_FLAGS);
            break;
        }
        LetterService::assertRegionAccess($existingRow);
        $existingRegionId = (int)$existingRow['region_id'];
        $previousIncomingRef = $existingRow['incoming_ref_id'] ?? null;

        $respondsToOutgoingId = !empty($data['responds_to_outgoing_id']) ? (int)$data['responds_to_outgoing_id'] : null;

        try {
            $db->beginTransaction();

            if ($type === 'incoming') {
                $seq = $data['seq'] ?? null;
                if (!$seq) {
                    $seq = computeNextSeq($db, $table, $existingRegionId);
                }
                $kkNumber = $data['kk_number'] ?? '';
                if ($respondsToOutgoingId && !$kkNumber) {
                    $generatedNumber = buildReplyNumber($db, (int)$seq, $respondsToOutgoingId);
                    if ($generatedNumber) {
                        $kkNumber = $generatedNumber;
                    }
                }
                $stmt = $db->prepare("
                    UPDATE incoming_letters 
                    SET seq = ?, date = ?, organization = ?, kk_number = ?, 
                        category = ?, subject = ?, note = ?, responds_to_outgoing_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $seq,
                    $data['date'] ?? date('Y-m-d'),
                    $data['organization'] ?? '',
                    $kkNumber,
                    $data['category'] ?? 'KK',
                    $data['subject'] ?? null,
                    $data['note'] ?? null,
                    $respondsToOutgoingId,
                    $id
                ]);
            } else {
                $seq = $data['seq'] ?? null;
                if (!$seq) {
                    $seq = computeNextSeq($db, $table, $existingRegionId);
                }
                $outgoingNumber = $data['outgoing_number'] ?? '';
                $outgoingType = normalizeOutgoingType($data['outgoing_type'] ?? null);
                if (!$outgoingNumber && !empty($data['incoming_ref_id'])) {
                    $stmtIncoming = $db->prepare("SELECT kk_number FROM incoming_letters WHERE id = ?");
                    $stmtIncoming->execute([$data['incoming_ref_id']]);
                    $kk = $stmtIncoming->fetchColumn();
                    $outgoingNumber = $kk ? "{$seq}/{$kk}" : (string)$seq;
                }
                if (!$outgoingNumber) {
                    $outgoingNumber = (string)$seq;
                }
                $stmt = $db->prepare("
                    UPDATE outgoing_letters 
                    SET seq = ?, date = ?, outgoing_number = ?, organization = ?, 
                        incoming_ref_id = ?, outgoing_type = ?, subject = ?, note = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $seq,
                    $data['date'] ?? date('Y-m-d'),
                    $outgoingNumber,
                    $data['organization'] ?? '',
                    $data['incoming_ref_id'] ?? null,
                    $outgoingType,
                    $data['subject'] ?? null,
                    $data['note'] ?? null,
                    $id
                ]);

                if ($previousIncomingRef && $previousIncomingRef !== ($data['incoming_ref_id'] ?? null)) {
                    $stmtUnlink = $db->prepare("UPDATE incoming_letters SET linked_outgoing_id = NULL WHERE id = ? AND linked_outgoing_id = ?");
                    $stmtUnlink->execute([$previousIncomingRef, $id]);
                }
                if (!empty($data['incoming_ref_id'])) {
                    $stmtLink = $db->prepare("UPDATE incoming_letters SET linked_outgoing_id = ? WHERE id = ?");
                    $stmtLink->execute([$id, $data['incoming_ref_id']]);
                }
            }

            $members = normalizeMembersPayload($data['members'] ?? []);
            $recipients = normalizeRecipientsPayload($data['recipients'] ?? []);
            syncLetterMembers($db, $type, (int)$id, $members);
            syncLetterRecipients($db, $type, (int)$id, $recipients);

            // Удаление отмеченных сканов
            if (!empty($data['delete_scan_ids']) && is_array($data['delete_scan_ids'])) {
                $ids = array_values(array_filter(array_map('intval', $data['delete_scan_ids'])));
                if ($ids) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmtDelScans = $db->prepare("DELETE FROM letter_scans WHERE letter_type = ? AND letter_id = ? AND id IN ($placeholders)");
                    $stmtDelScans->execute(array_merge([$type, $id], $ids));
                }
            }

            // Добавление новых сканов (если есть)
            if (!empty($data['scans']) && is_array($data['scans'])) {
                LetterService::insertScans($db, $type, (int)$id, $data['scans']);
            }

            AuditLogger::log($db, $table, (int)$id, 'UPDATE', null, $data, (int)($_SESSION['user_id'] ?? 0) ?: null);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            error_log('letters update failed: ' . $e->getMessage());
            echo json_encode(['error' => 'Внутренняя ошибка сервера'], $JSON_FLAGS);
            break;
        }

        pusherTrigger('council-documents', 'documents-updated', [
            'action' => 'update',
            'type' => $type,
            'id' => (int)$id,
            'region_id' => (int)$existingRegionId
        ]);
        echo json_encode(['message' => 'Письмо успешно обновлено'], $JSON_FLAGS);
        break;

    case 'DELETE':
        requireDeleteAccess();
        CsrfMiddleware::requireVerification();
        $id = $_GET['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID не указан'], $JSON_FLAGS);
            break;
        }

        $stmtRegion = $db->prepare("SELECT region_id FROM {$table} WHERE id = ?");
        $stmtRegion->execute([$id]);
        $letterRow = $stmtRegion->fetch();
        if (!$letterRow) {
            http_response_code(404);
            echo json_encode(['error' => 'Письмо не найдено'], $JSON_FLAGS);
            break;
        }
        LetterService::assertRegionAccess($letterRow);

        try {
            $db->beginTransaction();

            if ($type === 'outgoing') {
                $stmtUnlink = $db->prepare("UPDATE incoming_letters SET linked_outgoing_id = NULL WHERE linked_outgoing_id = ?");
                $stmtUnlink->execute([$id]);
            } else {
                $stmtDetach = $db->prepare("UPDATE outgoing_letters SET incoming_ref_id = NULL WHERE incoming_ref_id = ?");
                $stmtDetach->execute([$id]);
            }

            $stmtMembers = $db->prepare("DELETE FROM letter_members WHERE letter_type = ? AND letter_id = ?");
            $stmtMembers->execute([$type, $id]);
            $db->prepare("DELETE FROM letter_recipients WHERE letter_type = ? AND letter_id = ?")->execute([$type, $id]);

            $stmtDelete = $db->prepare("DELETE FROM {$table} WHERE id = ?");
            $stmtDelete->execute([$id]);

            AuditLogger::log($db, $table, (int)$id, 'DELETE', ['id' => (int)$id], null, (int)($_SESSION['user_id'] ?? 0) ?: null);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            error_log('letters delete failed: ' . $e->getMessage());
            echo json_encode(['error' => 'Внутренняя ошибка сервера'], $JSON_FLAGS);
            break;
        }

        pusherTrigger('council-documents', 'documents-updated', [
            'action' => 'delete',
            'type' => $type,
            'id' => (int)$id
        ]);
        echo json_encode(['message' => 'Письмо успешно удалено'], $JSON_FLAGS);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Метод не поддерживается'], $JSON_FLAGS);
}
