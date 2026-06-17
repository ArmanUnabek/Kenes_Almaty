<?php
require_once '../config.php';
require_once '../auth_middleware.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$JSON_FLAGS = JSON_ENCODE_FLAGS;

checkAuth();

$db = getDBConnection();
$user = getCurrentUser();
$regionId = $user['region_id'] ?? null;

try {
    $stats = [];

    $withRegion = static function (string $alias) use ($regionId): string {
        return $regionId ? " AND {$alias}.region_id = ? " : '';
    };
    $regionParams = $regionId ? [$regionId] : [];

    // Среднее время ответа (в календарных днях между входящим и связанным исходящим)
    if (DB_DRIVER === 'sqlite') {
        $diffExpr = "julianday(ol.date) - julianday(il.date)";
    } elseif (DB_DRIVER === 'pgsql') {
        $diffExpr = "ol.date - il.date";
    } else {
        $diffExpr = "DATEDIFF(ol.date, il.date)";
    }
    $sql = "
        SELECT AVG($diffExpr) as avg_response_days
        FROM outgoing_letters ol
        JOIN incoming_letters il ON ol.incoming_ref_id = il.id
        WHERE ol.incoming_ref_id IS NOT NULL
          {$withRegion('il')}
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($regionParams);
    $stats['avg_response_days'] = round($stmt->fetchColumn() ?? 0, 1);

    // Просроченные письма без ответа более 21 дня
    $sql = "
        SELECT COUNT(*) as overdue_count
        FROM incoming_letters il
        LEFT JOIN outgoing_letters ol ON ol.incoming_ref_id = il.id
        WHERE ol.id IS NULL 
        AND il.date < ? 
        {$withRegion('il')}
    ";
    $stmt = $db->prepare($sql);
    $params = array_merge([date('Y-m-d', strtotime('-21 days'))], $regionParams);
    $stmt->execute($params);
    $stats['overdue_letters'] = (int)$stmt->fetchColumn();

    // Письма по месяцам (последние 6 месяцев)
    $monthFmt = (DB_DRIVER === 'pgsql') ? "TO_CHAR(date, 'YYYY-MM')" : "SUBSTR(date, 1, 7)";
    $sql = "
        SELECT 
            $monthFmt as month,
            COUNT(*) as count,
            'incoming' as type
        FROM incoming_letters
        WHERE date >= ?
          {$withRegion('incoming_letters')}
        GROUP BY month
        UNION ALL
        SELECT 
            $monthFmt as month,
            COUNT(*) as count,
            'outgoing' as type
        FROM outgoing_letters
        WHERE date >= ?
          {$withRegion('outgoing_letters')}
        GROUP BY month
        ORDER BY month DESC
    ";
    $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
    $stmt = $db->prepare($sql);
    $params = array_merge([$sixMonthsAgo], $regionParams, [$sixMonthsAgo], $regionParams);
    $stmt->execute($params);
    $stats['monthly_trend'] = $stmt->fetchAll();

    // Топ-5 отправителей
    $sql = "
        SELECT organization, COUNT(*) as count
        FROM incoming_letters
        WHERE 1=1
          {$withRegion('incoming_letters')}
        GROUP BY organization
        ORDER BY count DESC
        LIMIT 5
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($regionParams);
    $stats['top_senders'] = $stmt->fetchAll();

    // Топ-5 получателей
    $sql = "
        SELECT lr.recipient, COUNT(*) as count
        FROM letter_recipients lr
        JOIN outgoing_letters ol ON ol.id = lr.letter_id
        WHERE lr.letter_type = 'outgoing'
          {$withRegion('ol')}
        GROUP BY lr.recipient
        ORDER BY count DESC
        LIMIT 5
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($regionParams);
    $stats['top_recipients'] = $stmt->fetchAll();

    // Процент писем со сканами (считаем письма, а не число файлов)
    $sql = "
        SELECT 
            (SELECT COUNT(DISTINCT il.id) FROM incoming_letters il
             JOIN letter_scans ls ON ls.letter_id = il.id AND ls.letter_type = 'incoming'
             WHERE 1=1 {$withRegion('il')}) +
            (SELECT COUNT(DISTINCT ol.id) FROM outgoing_letters ol
             JOIN letter_scans ls ON ls.letter_id = ol.id AND ls.letter_type = 'outgoing'
             WHERE 1=1 {$withRegion('ol')}) as with_scans,
            (SELECT COUNT(*) FROM incoming_letters il WHERE 1=1 {$withRegion('il')}) +
            (SELECT COUNT(*) FROM outgoing_letters ol WHERE 1=1 {$withRegion('ol')}) as total
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($regionParams, $regionParams, $regionParams, $regionParams));
    $scanStats = $stmt->fetch();
    $stats['scans_percentage'] = $scanStats['total'] > 0 
        ? round(($scanStats['with_scans'] / $scanStats['total']) * 100, 1) 
        : 0;

    // Рейтинг членов по активности
    $sql = "
        SELECT 
            m.id,
            m.full_name,
            m.commission_id,
            c.name as commission_name,
            COALESCE(SUM(CASE WHEN lm.letter_type = 'incoming' THEN 1 ELSE 0 END), 0) as incoming_count,
            COALESCE(SUM(CASE WHEN lm.letter_type = 'outgoing' THEN 1 ELSE 0 END), 0) as outgoing_count,
            COALESCE(SUM(CASE WHEN lm.is_lead = 1 THEN 1 ELSE 0 END), 0) as lead_count
        FROM os_members m
        LEFT JOIN commissions c ON c.id = m.commission_id
        LEFT JOIN letter_members lm ON lm.member_id = m.id
        WHERE m.status = 'active'
          " . ($regionId ? " AND m.region_id = ? " : "") . "
        GROUP BY m.id
        HAVING COALESCE(SUM(CASE WHEN lm.letter_type IN ('incoming','outgoing') THEN 1 ELSE 0 END), 0) > 0
        ORDER BY COALESCE(SUM(CASE WHEN lm.letter_type IN ('incoming','outgoing') THEN 1 ELSE 0 END), 0) DESC, lead_count DESC
        LIMIT 10
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($regionParams);
    $stats['top_members'] = $stmt->fetchAll();

    // Неактивные члены (без писем)
    $sql = "
        SELECT 
            m.id,
            m.full_name,
            c.name as commission_name
        FROM os_members m
        LEFT JOIN commissions c ON c.id = m.commission_id
        LEFT JOIN letter_members lm ON lm.member_id = m.id
        WHERE m.status = 'active'
          " . ($regionId ? " AND m.region_id = ? " : "") . "
        GROUP BY m.id
        HAVING COUNT(lm.id) = 0
        ORDER BY m.full_name
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($regionParams);
    $stats['inactive_members'] = $stmt->fetchAll();

    // Процент членов с фото
    $sql = "
        SELECT 
            COUNT(CASE WHEN photo_path IS NOT NULL AND photo_path != '' THEN 1 END) as with_photo,
            COUNT(*) as total
        FROM os_members
        WHERE status = 'active'
          " . ($regionId ? " AND region_id = ? " : "") . "
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($regionParams);
    $photoStats = $stmt->fetch();
    $stats['members_with_photo_percentage'] = $photoStats['total'] > 0 
        ? round(($photoStats['with_photo'] / $photoStats['total']) * 100, 1) 
        : 0;

    // Производительность комиссий
    $sql = "
        SELECT 
            c.id,
            c.name,
            c.color,
            COUNT(DISTINCT m.id) as members_count,
            COALESCE(SUM(CASE WHEN lm.letter_type = 'incoming' THEN 1 ELSE 0 END), 0) as incoming_count,
            COALESCE(SUM(CASE WHEN lm.letter_type = 'outgoing' THEN 1 ELSE 0 END), 0) as outgoing_count
        FROM commissions c
        LEFT JOIN os_members m ON m.commission_id = c.id AND m.status = 'active'
          " . ($regionId ? " AND m.region_id = ? " : "") . "
        LEFT JOIN letter_members lm ON lm.member_id = m.id
        GROUP BY c.id
        ORDER BY COALESCE(SUM(CASE WHEN lm.letter_type IN ('incoming','outgoing') THEN 1 ELSE 0 END), 0) DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($regionParams);
    $commissions = $stmt->fetchAll();

    foreach ($commissions as &$comm) {
        $comm['avg_load'] = $comm['members_count'] > 0 
            ? round(($comm['incoming_count'] + $comm['outgoing_count']) / $comm['members_count'], 1)
            : 0;
    }
    unset($comm);
    $stats['commission_performance'] = $commissions;

    // Письма по типам
    $sql = "
        SELECT category, COUNT(*) as count
        FROM incoming_letters
        WHERE 1=1
          {$withRegion('incoming_letters')}
        GROUP BY category
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($regionParams);
    $stats['letters_by_type'] = $stmt->fetchAll();

    // Сравнение с прошлым периодом
    $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
    $sixtyDaysAgo = date('Y-m-d', strtotime('-60 days'));
    $sql = "
        SELECT 
            (SELECT COUNT(*) FROM incoming_letters 
             WHERE date >= ?
               {$withRegion('incoming_letters')}) as current_month_incoming,
            (SELECT COUNT(*) FROM incoming_letters 
             WHERE date >= ? AND date < ?
               {$withRegion('incoming_letters')}) as prev_month_incoming,
            (SELECT COUNT(*) FROM outgoing_letters 
             WHERE date >= ?
               {$withRegion('outgoing_letters')}) as current_month_outgoing,
            (SELECT COUNT(*) FROM outgoing_letters 
             WHERE date >= ? AND date < ?
               {$withRegion('outgoing_letters')}) as prev_month_outgoing
    ";
    $stmt = $db->prepare($sql);
    $params = array_merge([$thirtyDaysAgo], $regionParams, [$sixtyDaysAgo, $thirtyDaysAgo], $regionParams, [$thirtyDaysAgo], $regionParams, [$sixtyDaysAgo, $thirtyDaysAgo], $regionParams);
    $stmt->execute($params);
    $comparison = $stmt->fetch();

    $calcChange = static function ($current, $previous): float {
        $current = (float)$current;
        $previous = (float)$previous;
        if ($previous <= 0.0) {
            return $current > 0.0 ? 100.0 : 0.0;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    };

    $stats['trend_comparison'] = [
        'incoming_change' => $calcChange($comparison['current_month_incoming'] ?? 0, $comparison['prev_month_incoming'] ?? 0),
        'outgoing_change' => $calcChange($comparison['current_month_outgoing'] ?? 0, $comparison['prev_month_outgoing'] ?? 0)
    ];

    // Процент писем с ответами в срок (до 21 календарного дня)
    if (DB_DRIVER === 'sqlite') {
        $diffCond = "(julianday(ol.date) - julianday(il.date)) <= 21";
    } elseif (DB_DRIVER === 'pgsql') {
        $diffCond = "(ol.date - il.date) <= 21";
    } else {
        $diffCond = "DATEDIFF(ol.date, il.date) <= 21";
    }
    $sql = "
        SELECT 
            COUNT(CASE WHEN $diffCond THEN 1 END) as on_time,
            COUNT(*) as total
        FROM outgoing_letters ol
        JOIN incoming_letters il ON ol.incoming_ref_id = il.id
        WHERE ol.incoming_ref_id IS NOT NULL
          {$withRegion('il')}
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($regionParams);
    $timeStats = $stmt->fetch();
    $stats['on_time_percentage'] = $timeStats['total'] > 0 
        ? round(($timeStats['on_time'] / $timeStats['total']) * 100, 1) 
        : 0;

    echo json_encode($stats, $JSON_FLAGS);

} catch (Throwable $e) {
    http_response_code(500);
    error_log('advanced_stats failed: ' . $e->getMessage());
    echo json_encode(['error' => 'Внутренняя ошибка сервера'], $JSON_FLAGS);
}
