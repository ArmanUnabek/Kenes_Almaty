<?php
require_once __DIR__ . '/../db.php';

configureSessionCookie();
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = getDBConnection();
    $region_id = $_SESSION['region_id'] ?? 1;

    // Получить статистику
    $stats = [
        'total_incoming' => 0,
        'total_outgoing' => 0,
        'closed_letters' => 0,
        'avg_response_days' => 0,
        'pending_letters' => 0,
        'letters_with_scans' => 0,
        'total_scans' => 0,
        'members_count' => 0,
        'commissions_count' => 0,
        'members_with_photo' => 0,
        'overdue_letters' => 0,
        'on_time_percentage' => 0,
        'scans_percentage' => 0
    ];

    // Входящие письма
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM incoming_letters WHERE region_id = ?');
    $stmt->execute([$region_id]);
    $stats['total_incoming'] = (int)$stmt->fetch()['cnt'];

    // Исходящие письма
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM outgoing_letters WHERE region_id = ?');
    $stmt->execute([$region_id]);
    $stats['total_outgoing'] = (int)$stmt->fetch()['cnt'];

    // Закрытые письма (входящие с ответом)
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM incoming_letters WHERE region_id = ? AND linked_outgoing_id IS NOT NULL');
    $stmt->execute([$region_id]);
    $stats['closed_letters'] = (int)$stmt->fetch()['cnt'];

    // Письма без ответа
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM incoming_letters WHERE region_id = ? AND linked_outgoing_id IS NULL');
    $stmt->execute([$region_id]);
    $stats['pending_letters'] = (int)$stmt->fetch()['cnt'];

    // Письма со сканами
    $stmt = $db->prepare('SELECT COUNT(DISTINCT letter_id) as cnt FROM letter_scans WHERE letter_type = "incoming"');
    $stmt->execute();
    $stats['letters_with_scans'] = (int)$stmt->fetch()['cnt'];

    // Всего сканов
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM letter_scans');
    $stmt->execute();
    $stats['total_scans'] = (int)$stmt->fetch()['cnt'];

    // Члены ОС
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM os_members WHERE region_id = ?');
    $stmt->execute([$region_id]);
    $stats['members_count'] = (int)$stmt->fetch()['cnt'];

    // Комиссии
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM commissions WHERE region_id = ?');
    $stmt->execute([$region_id]);
    $stats['commissions_count'] = (int)$stmt->fetch()['cnt'];

    // Члены с фото
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM os_members WHERE region_id = ? AND photo_path IS NOT NULL');
    $stmt->execute([$region_id]);
    $stats['members_with_photo'] = (int)$stmt->fetch()['cnt'];

    // Просрочено (более 21 дня без ответа)
    $thresholdDate = date('Y-m-d', strtotime('-21 days'));
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM incoming_letters WHERE region_id = ? AND linked_outgoing_id IS NULL AND date < ?');
    $stmt->execute([$region_id, $thresholdDate]);
    $stats['overdue_letters'] = (int)$stmt->fetch()['cnt'];

    // Процент ответов в срок
    if ($stats['total_incoming'] > 0) {
        $on_time = $stats['closed_letters'] - $stats['overdue_letters'];
        $stats['on_time_percentage'] = round(($on_time / $stats['total_incoming']) * 100);
    }

    // Процент писем со сканами
    if ($stats['total_incoming'] > 0) {
        $stats['scans_percentage'] = round(($stats['letters_with_scans'] / $stats['total_incoming']) * 100);
    }

    // Средний срок ответа
    if (DB_DRIVER === 'sqlite') {
        $avgQuery = 'SELECT AVG(julianday(ol.date) - julianday(il.date)) as avg_days FROM incoming_letters il LEFT JOIN outgoing_letters ol ON il.linked_outgoing_id = ol.id WHERE il.region_id = ? AND ol.date IS NOT NULL';
    } elseif (DB_DRIVER === 'pgsql') {
        $avgQuery = 'SELECT AVG(ol.date - il.date) as avg_days FROM incoming_letters il LEFT JOIN outgoing_letters ol ON il.linked_outgoing_id = ol.id WHERE il.region_id = ? AND ol.date IS NOT NULL';
    } else {
        $avgQuery = 'SELECT AVG(DATEDIFF(ol.date, il.date)) as avg_days FROM incoming_letters il LEFT JOIN outgoing_letters ol ON il.linked_outgoing_id = ol.id WHERE il.region_id = ? AND ol.date IS NOT NULL';
    }
    $stmt = $db->prepare($avgQuery);
    $stmt->execute([$region_id]);
    $result = $stmt->fetch();
    $stats['avg_response_days'] = $result['avg_days'] ? round($result['avg_days']) : 0;

    echo json_encode($stats, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    error_log('statistics failed: ' . $e->getMessage());
    echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_UNESCAPED_UNICODE);
}
