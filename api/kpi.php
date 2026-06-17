<?php
require_once '../config.php';
require_once '../auth_middleware.php';

use App\Services\FileCache;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$JSON_FLAGS = JSON_ENCODE_FLAGS;

checkAuth();

$db = getDBConnection();
$user = getCurrentUser();
$regionId = $user['region_id'] ?? null;
$cache = new FileCache();
$cacheKey = 'kpi:stats:' . ($regionId ?: 'global');
$cached = $cache->get($cacheKey);
if ($cached) {
    echo json_encode($cached, $JSON_FLAGS);
    exit;
}

try {
    // KPI по членам ОС: количество входящих, где назначен ответственным, и исходящих, где назначен ответственным
    // Важно: строим KPI от os_members, а не от letter_members,
    // чтобы в ответе были все активные члены (пусть даже с нулями).
    $params = [];

    $memberRegionClause = '';
    if ($regionId) {
        $memberRegionClause = ' AND m.region_id = ? ';
        $params[] = $regionId;
    }

    if ($regionId) {
        $sqlMembers = "
            SELECT
                m.id,
                m.full_name,
                m.position,
                m.commission_id,
                c.name AS commission_name,
                c.color AS commission_color,
                COALESCE(SUM(CASE WHEN lm.letter_type = 'incoming' AND il.region_id = ? THEN 1 ELSE 0 END), 0) AS incoming_count,
                COALESCE(SUM(CASE WHEN lm.letter_type = 'outgoing' AND ol.region_id = ? THEN 1 ELSE 0 END), 0) AS outgoing_count,
                COALESCE(SUM(
                    CASE
                        WHEN lm.is_lead = 1
                         AND (
                            (lm.letter_type = 'incoming' AND il.region_id = ?)
                            OR (lm.letter_type = 'outgoing' AND ol.region_id = ?)
                         )
                        THEN 1
                        ELSE 0
                    END
                ), 0) AS lead_count
            FROM os_members m
            LEFT JOIN commissions c ON c.id = m.commission_id
            LEFT JOIN letter_members lm ON lm.member_id = m.id
            LEFT JOIN incoming_letters il ON il.id = lm.letter_id AND lm.letter_type = 'incoming'
            LEFT JOIN outgoing_letters ol ON ol.id = lm.letter_id AND lm.letter_type = 'outgoing'
            WHERE m.status = 'active'
              {$memberRegionClause}
            GROUP BY m.id, m.full_name, m.position, m.commission_id, c.name, c.color
            ORDER BY outgoing_count DESC, incoming_count DESC
        ";
        // Порядок плейсхолдеров в CASE соответствует:
        // incoming_count(1), outgoing_count(1), lead_count(il)(1), lead_count(ol)(1)
        $params = array_merge($params, [$regionId, $regionId, $regionId, $regionId]);
    } else {
        $sqlMembers = "
            SELECT
                m.id,
                m.full_name,
                m.position,
                m.commission_id,
                c.name AS commission_name,
                c.color AS commission_color,
                COALESCE(SUM(CASE WHEN lm.letter_type = 'incoming' THEN 1 ELSE 0 END), 0) AS incoming_count,
                COALESCE(SUM(CASE WHEN lm.letter_type = 'outgoing' THEN 1 ELSE 0 END), 0) AS outgoing_count,
                COALESCE(SUM(CASE WHEN lm.is_lead = 1 THEN 1 ELSE 0 END), 0) AS lead_count
            FROM os_members m
            LEFT JOIN commissions c ON c.id = m.commission_id
            LEFT JOIN letter_members lm ON lm.member_id = m.id
            LEFT JOIN incoming_letters il ON il.id = lm.letter_id AND lm.letter_type = 'incoming'
            LEFT JOIN outgoing_letters ol ON ol.id = lm.letter_id AND lm.letter_type = 'outgoing'
            WHERE m.status = 'active'
            GROUP BY m.id, m.full_name, m.position, m.commission_id, c.name, c.color
            ORDER BY outgoing_count DESC, incoming_count DESC
        ";
    }

    $stmt = $db->prepare($sqlMembers);
    $stmt->execute($params);
    $membersKpi = $stmt->fetchAll();

    // KPI по комиссиям (агрегировано по членам комиссии)
    $params2 = [];
    $regionFilterCommissions = '';
    if ($regionId) {
        $regionFilterCommissions = "
            AND (
                lm.letter_type IS NULL
                OR (lm.letter_type = 'incoming' AND il.region_id = ?)
                OR (lm.letter_type = 'outgoing' AND ol.region_id = ?)
            )
        ";
        $params2[] = $regionId;
        $params2[] = $regionId;
    }
    $sqlCommissions = "
        SELECT
            c.id,
            c.name,
            c.color,
            COALESCE(SUM(CASE WHEN lm.letter_type = 'incoming' THEN 1 ELSE 0 END),0) AS incoming_count,
            COALESCE(SUM(CASE WHEN lm.letter_type = 'outgoing' THEN 1 ELSE 0 END),0) AS outgoing_count
        FROM commissions c
        LEFT JOIN os_members m ON m.commission_id = c.id
        LEFT JOIN letter_members lm ON lm.member_id = m.id
        LEFT JOIN incoming_letters il ON il.id = lm.letter_id AND lm.letter_type = 'incoming'
        LEFT JOIN outgoing_letters ol ON ol.id = lm.letter_id AND lm.letter_type = 'outgoing'
        WHERE 1 = 1
        {$regionFilterCommissions}
        GROUP BY c.id
        ORDER BY outgoing_count DESC, incoming_count DESC, c.sort_order ASC, c.name ASC
    ";
    $stmt2 = $db->prepare($sqlCommissions);
    $stmt2->execute($params2);
    $commissionsKpi = $stmt2->fetchAll();

    // Сводка «Ответственные члены ОС»: Председатель ОС, 6 председателей комиссий, затем остальные
    // Находим Председателя ОС: берём первого активного с position LIKE '%Председатель%' и commission_id IS NULL OR 0
    $chairSql = "
        SELECT m.id, m.full_name, m.position, m.commission_id, c.name AS commission_name, c.color AS commission_color
        FROM os_members m
        LEFT JOIN commissions c ON c.id = m.commission_id
        WHERE m.status = 'active'
        " . ($regionId ? " AND m.region_id = ? " : "") . "
        ORDER BY 
            (CASE WHEN (m.position LIKE '%Председатель%' AND (m.commission_id IS NULL OR m.commission_id = 0)) THEN 0 ELSE 1 END),
            m.id ASC
        LIMIT 1
    ";
    $stmtChair = $db->prepare($chairSql);
    $stmtChair->execute($regionId ? [$regionId] : []);
    $chair = $stmtChair->fetch();

    // Председатели комиссий: строго по нормализованной роли, чтобы не ловить "председатель ассоциации" и т.п.
    $chairsCommissionsSql = "
        SELECT m.id, m.full_name, m.position, m.commission_id, c.name AS commission_name, c.color AS commission_color, c.sort_order
        FROM os_members m
        JOIN commissions c ON c.id = m.commission_id
        WHERE m.status = 'active'
          AND m.position LIKE 'Председатель комиссии.%'
        " . ($regionId ? " AND m.region_id = ? " : "") . "
        ORDER BY c.sort_order ASC, c.name ASC, m.id ASC
    ";
    $stmtChairs = $db->prepare($chairsCommissionsSql);
    $stmtChairs->execute($regionId ? [$regionId] : []);
    $chairsRows = $stmtChairs->fetchAll();
    $chairsCommissions = [];
    $seenCommissionIds = [];
    foreach ($chairsRows as $row) {
        $cid = (int)($row['commission_id'] ?? 0);
        if (!$cid) continue;
        if (isset($seenCommissionIds[$cid])) continue;
        $seenCommissionIds[$cid] = true;
        $chairsCommissions[] = $row;
        if (count($chairsCommissions) >= 6) break;
    }

    // Остальные активные члены, отсортированные по комиссии
    $excludedIds = [];
    if (!empty($chair['id'])) {
        $excludedIds[] = (int)$chair['id'];
    }
    foreach ($chairsCommissions as $person) {
        if (!empty($person['id'])) {
            $excludedIds[] = (int)$person['id'];
        }
    }
    $excludedIds = array_values(array_unique($excludedIds));
    $excludeClause = '';
    if (!empty($excludedIds)) {
        $excludeClause = ' AND m.id NOT IN (' . implode(',', array_fill(0, count($excludedIds), '?')) . ')';
    }
    $othersSql = "
        SELECT m.id, m.full_name, m.position, m.commission_id, c.name AS commission_name, c.color AS commission_color
        FROM os_members m
        LEFT JOIN commissions c ON c.id = m.commission_id
        WHERE m.status = 'active'
          " . ($regionId ? " AND m.region_id = ? " : "") . "
          {$excludeClause}
        ORDER BY c.sort_order ASC, c.name ASC, m.full_name ASC
        LIMIT 100
    ";
    $othersParams = [];
    if ($regionId) {
        $othersParams[] = $regionId;
    }
    if (!empty($excludedIds)) {
        $othersParams = array_merge($othersParams, $excludedIds);
    }
    $stmtOthers = $db->prepare($othersSql);
    $stmtOthers->execute($othersParams);
    $others = $stmtOthers->fetchAll();

    // KPI: участие членов ОС в мероприятиях (по ФИО)
    $params3 = [];
    $regionFilterEvents = '';
    if ($regionId) {
        $regionFilterEvents = ' WHERE e.region_id = ? ';
        $params3[] = $regionId;
    }
    $sqlEventsByMember = "
        SELECT m.id, m.full_name, COALESCE(SUM(CASE WHEN ea.attended = 1 THEN 1 ELSE 0 END),0) AS events_attended
        FROM os_members m
        LEFT JOIN event_attendees ea ON ea.full_name = m.full_name
        LEFT JOIN events e ON e.id = ea.event_id
        " . ($regionFilterEvents ? $regionFilterEvents : "") . "
        GROUP BY m.id
        ORDER BY events_attended DESC, m.full_name ASC
    ";
    $stmt3 = $db->prepare($sqlEventsByMember);
    $stmt3->execute($params3);
    $eventsByMember = $stmt3->fetchAll();

    // KPI: адресаты писем
    $paramsRecIncoming = [];
    $regionFilterRecIncoming = '';
    if ($regionId) {
        $regionFilterRecIncoming = ' AND il.region_id = ? ';
        $paramsRecIncoming[] = $regionId;
    }
    $sqlRecipientsIncoming = "
        SELECT lr.recipient, COUNT(*) AS total
        FROM letter_recipients lr
        JOIN incoming_letters il ON il.id = lr.letter_id
        WHERE lr.letter_type = 'incoming'
        {$regionFilterRecIncoming}
        GROUP BY lr.recipient
        ORDER BY total DESC, lr.recipient ASC
        LIMIT 20
    ";
    $stmtRecIn = $db->prepare($sqlRecipientsIncoming);
    $stmtRecIn->execute($paramsRecIncoming);
    $recipientsIncoming = $stmtRecIn->fetchAll();

    $paramsRecOutgoing = [];
    $regionFilterRecOutgoing = '';
    if ($regionId) {
        $regionFilterRecOutgoing = ' AND ol.region_id = ? ';
        $paramsRecOutgoing[] = $regionId;
    }
    $sqlRecipientsOutgoing = "
        SELECT lr.recipient, COUNT(*) AS total
        FROM letter_recipients lr
        JOIN outgoing_letters ol ON ol.id = lr.letter_id
        WHERE lr.letter_type = 'outgoing'
        {$regionFilterRecOutgoing}
        GROUP BY lr.recipient
        ORDER BY total DESC, lr.recipient ASC
        LIMIT 20
    ";
    $stmtRecOut = $db->prepare($sqlRecipientsOutgoing);
    $stmtRecOut->execute($paramsRecOutgoing);
    $recipientsOutgoing = $stmtRecOut->fetchAll();

    $payload = [
        'members' => $membersKpi,
        'commissions' => $commissionsKpi,
        'summary' => [
            'chair' => $chair ?: null,
            'chairs_commissions' => $chairsCommissions,
            'others' => $others
        ],
        'events_participation' => $eventsByMember,
        'recipients' => [
            'incoming' => $recipientsIncoming,
            'outgoing' => $recipientsOutgoing
        ]
    ];
    $cache->set($cacheKey, $payload, 1800);
    echo json_encode($payload, $JSON_FLAGS);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('kpi failed: ' . $e->getMessage());
    echo json_encode(['error' => 'Внутренняя ошибка сервера'], $JSON_FLAGS);
}


