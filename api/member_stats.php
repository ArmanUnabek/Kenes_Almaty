<?php
/**
 * GET /api/member_stats.php?member_id=N
 * Возвращает детальную статистику одного члена ОС по данным из letter_members.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

header('Content-Type: application/json; charset=utf-8');

checkAuth();

$memberId = (int)($_GET['member_id'] ?? 0);
if ($memberId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'member_id обязателен'], JSON_ENCODE_FLAGS);
    exit;
}

$db = getDBConnection();

// Проверка что член ОС принадлежит доступному региону
$stmtMember = $db->prepare("SELECT id, full_name, region_id, commission_id, photo_path FROM os_members WHERE id = ?");
$stmtMember->execute([$memberId]);
$member = $stmtMember->fetch();

if (!$member) {
    http_response_code(404);
    echo json_encode(['error' => 'Член ОС не найден'], JSON_ENCODE_FLAGS);
    exit;
}

if (!canAccessRegion((int)$member['region_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещён'], JSON_ENCODE_FLAGS);
    exit;
}

try {
    // ── Итоговые счётчики ─────────────────────────────────────────────────────
    $stmtCounts = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN lm.letter_type = 'incoming' THEN 1 ELSE 0 END), 0) AS assigned_incoming,
            COALESCE(SUM(CASE WHEN lm.letter_type = 'outgoing' THEN 1 ELSE 0 END), 0) AS assigned_outgoing,
            COALESCE(SUM(CASE WHEN lm.is_lead = 1 THEN 1 ELSE 0 END), 0)             AS lead_count
        FROM letter_members lm
        WHERE lm.member_id = ?
    ");
    $stmtCounts->execute([$memberId]);
    $counts = $stmtCounts->fetch();

    // ── Закрытые входящие (с ответом) ─────────────────────────────────────────
    $stmtClosed = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM letter_members lm
        JOIN incoming_letters il ON il.id = lm.letter_id AND lm.letter_type = 'incoming'
        WHERE lm.member_id = ?
          AND il.linked_outgoing_id IS NOT NULL
    ");
    $stmtClosed->execute([$memberId]);
    $closedIncoming = (int)$stmtClosed->fetchColumn();

    // ── Открытые (без ответа) ─────────────────────────────────────────────────
    $openIncoming = (int)$counts['assigned_incoming'] - $closedIncoming;

    // ── Просроченные: без ответа старше 21 кал. дня (≈15 рабочих) ───────────
    $cutoff = date('Y-m-d', strtotime('-21 days'));
    $stmtOverdue = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM letter_members lm
        JOIN incoming_letters il ON il.id = lm.letter_id AND lm.letter_type = 'incoming'
        WHERE lm.member_id = ?
          AND il.linked_outgoing_id IS NULL
          AND il.date < ?
    ");
    $stmtOverdue->execute([$memberId, $cutoff]);
    $overdueIncoming = (int)$stmtOverdue->fetchColumn();

    // ── Процент ответов ────────────────────────────────────────────────────────
    $assignedIncoming = (int)$counts['assigned_incoming'];
    $responseRate = $assignedIncoming > 0 ? round($closedIncoming / $assignedIncoming * 100, 1) : null;

    // ── Последние 5 входящих писем, назначенных этому члену ───────────────────
    $stmtRecent = $db->prepare("
        SELECT il.id, il.seq, il.date, il.organization, il.kk_number,
               lm.is_lead,
               (il.linked_outgoing_id IS NOT NULL) AS is_closed
        FROM letter_members lm
        JOIN incoming_letters il ON il.id = lm.letter_id AND lm.letter_type = 'incoming'
        WHERE lm.member_id = ?
        ORDER BY il.date DESC
        LIMIT 5
    ");
    $stmtRecent->execute([$memberId]);
    $recentLetters = $stmtRecent->fetchAll();

    foreach ($recentLetters as &$row) {
        $row['is_closed'] = (bool)$row['is_closed'];
        $row['is_lead']   = (bool)$row['is_lead'];
    }
    unset($row);

    echo json_encode([
        'member_id'        => $memberId,
        'assigned_incoming'=> $assignedIncoming,
        'assigned_outgoing'=> (int)$counts['assigned_outgoing'],
        'closed_incoming'  => $closedIncoming,
        'open_incoming'    => $openIncoming,
        'overdue_incoming' => $overdueIncoming,
        'lead_count'       => (int)$counts['lead_count'],
        'response_rate'    => $responseRate,
        'recent_letters'   => $recentLetters,
    ], JSON_ENCODE_FLAGS);

} catch (\Throwable $e) {
    http_response_code(500);
    error_log('member_stats failed: ' . $e->getMessage());
    echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_ENCODE_FLAGS);
}
