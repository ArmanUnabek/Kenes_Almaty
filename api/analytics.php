<?php

require_once __DIR__ . '/../config.php';

use App\Services\ApiKeyService;
use App\Services\FileCache;

header('Content-Type: application/json; charset=utf-8');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(ak_\S+)$/i', $authHeader, $m)) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется API-ключ. Отправьте Authorization: Bearer ak_...'], JSON_ENCODE_FLAGS);
    exit;
}

$db = getDBConnection();
$user = ApiKeyService::validateKey($db, $m[1]);
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Недействительный или отозванный API-ключ'], JSON_ENCODE_FLAGS);
    exit;
}

$period   = strtolower(trim((string)($_GET['period'] ?? 'month')));
$regionId = isset($_GET['region_id']) ? (int)$_GET['region_id'] : null;

$now = new DateTimeImmutable();
if ($period === 'year') {
    $dtFrom = $now->modify('-365 days');
} else {
    $dtFrom = $now->modify('-30 days');
    $period = 'month';
}
$dtTo   = $now;
$fromStr = $dtFrom->format('Y-m-d');
$toStr   = $dtTo->format('Y-m-d');

$cacheKey = 'ext_analytics:' . md5($period . ($regionId ?: 'all') . $fromStr . $toStr);
$cache    = new FileCache();
$cached   = $cache->get($cacheKey);
if ($cached !== null) {
    echo json_encode($cached, JSON_ENCODE_FLAGS);
    exit;
}

$driver = DB_DRIVER;

$dateFmt = match ($driver) {
    'pgsql'   => "TO_CHAR(date, 'YYYY-MM-DD')",
    'sqlite'  => "strftime('%Y-%m-%d', date)",
    default   => 'DATE(date)',
};

$avgDaysExpr = match ($driver) {
    'sqlite'  => 'julianday(ol.date) - julianday(il.date)',
    'pgsql'   => 'ol.date - il.date',
    default   => 'DATEDIFF(ol.date, il.date)',
};

$rWh = $regionId ? ' AND c.region_id = ?' : '';
$rp  = $regionId ? [$regionId] : [];

$safeQuery = static function (callable $fn) {
    try {
        return $fn();
    } catch (\Throwable $e) {
        return null;
    }
};

// ── 1. Letters by region (incoming / outgoing counts) ─────────────────────────
$lettersByRegion = $safeQuery(function () use ($db, $fromStr, $toStr, $regionId) {
    if ($regionId) {
        $s = $db->prepare("
            SELECT r.id AS region_id, r.name_ru AS region_name,
                   COALESCE(inc.cnt, 0) AS incoming,
                   COALESCE(out.cnt, 0) AS outgoing
            FROM regions r
            LEFT JOIN (
                SELECT region_id, COUNT(*) AS cnt FROM incoming_letters
                WHERE date BETWEEN ? AND ? AND region_id = ?
                GROUP BY region_id
            ) inc ON inc.region_id = r.id
            LEFT JOIN (
                SELECT region_id, COUNT(*) AS cnt FROM outgoing_letters
                WHERE date BETWEEN ? AND ? AND region_id = ?
                GROUP BY region_id
            ) out ON out.region_id = r.id
            WHERE r.is_active = 1 AND r.id = ?
            ORDER BY r.name_ru
        ");
        $s->execute([$fromStr, $toStr, $regionId, $fromStr, $toStr, $regionId, $regionId]);
    } else {
        $s = $db->prepare("
            SELECT r.id AS region_id, r.name_ru AS region_name,
                   COALESCE(inc.cnt, 0) AS incoming,
                   COALESCE(out.cnt, 0) AS outgoing
            FROM regions r
            LEFT JOIN (
                SELECT region_id, COUNT(*) AS cnt FROM incoming_letters
                WHERE date BETWEEN ? AND ?
                GROUP BY region_id
            ) inc ON inc.region_id = r.id
            LEFT JOIN (
                SELECT region_id, COUNT(*) AS cnt FROM outgoing_letters
                WHERE date BETWEEN ? AND ?
                GROUP BY region_id
            ) out ON out.region_id = r.id
            WHERE r.is_active = 1
            ORDER BY r.name_ru
        ");
        $s->execute([$fromStr, $toStr, $fromStr, $toStr]);
    }
    return $s->fetchAll();
}) ?? [];

// ── 2. Members by commission ──────────────────────────────────────────────────
$membersByCommission = $safeQuery(function () use ($db, $rWh, $rp) {
    $s = $db->prepare("
        SELECT c.id AS commission_id, c.name AS commission_name,
               COUNT(m.id) AS member_count
        FROM commissions c
        LEFT JOIN os_members m ON m.commission_id = c.id AND m.status = 'active'
        WHERE 1=1{$rWh}
        GROUP BY c.id, c.name
        ORDER BY member_count DESC
    ");
    $s->execute($rp);
    return $s->fetchAll();
}) ?? [];

// ── 3. Average response time ─────────────────────────────────────────────────
$avgResponseDays = $safeQuery(function () use ($db, $avgDaysExpr, $fromStr, $toStr, $rWh, $rp) {
    $s = $db->prepare("
        SELECT AVG({$avgDaysExpr}) AS avg_days
        FROM incoming_letters il
        JOIN outgoing_letters ol ON ol.incoming_ref_id = il.id
        WHERE il.date BETWEEN ? AND ?{$rWh}
    ");
    $s->execute(array_merge([$fromStr, $toStr], $rp));
    $result = $s->fetch();
    return $result['avg_days'] !== null ? round((float)$result['avg_days'], 1) : null;
});

// ── 4. Top categories ────────────────────────────────────────────────────────
$topCategories = $safeQuery(function () use ($db, $fromStr, $toStr, $rWh, $rp) {
    $s = $db->prepare("
        SELECT COALESCE(category, 'Без категории') AS category, COUNT(*) AS cnt
        FROM incoming_letters
        WHERE date BETWEEN ? AND ?{$rWh}
        GROUP BY category
        ORDER BY cnt DESC
        LIMIT 10
    ");
    $s->execute(array_merge([$fromStr, $toStr], $rp));
    return $s->fetchAll();
}) ?? [];

// ── 5. Summary stats ─────────────────────────────────────────────────────────
$totalIncoming = $safeQuery(function () use ($db, $fromStr, $toStr, $rWh, $rp) {
    $s = $db->prepare("SELECT COUNT(*) FROM incoming_letters WHERE date BETWEEN ? AND ?{$rWh}");
    $s->execute(array_merge([$fromStr, $toStr], $rp));
    return (int)$s->fetchColumn();
}) ?? 0;

$totalOutgoing = $safeQuery(function () use ($db, $fromStr, $toStr, $rWh, $rp) {
    $s = $db->prepare("SELECT COUNT(*) FROM outgoing_letters WHERE date BETWEEN ? AND ?{$rWh}");
    $s->execute(array_merge([$fromStr, $toStr], $rp));
    return (int)$s->fetchColumn();
}) ?? 0;

$deadline = date('Y-m-d', strtotime('-21 days'));
$overdueLetters = $safeQuery(function () use ($db, $deadline, $rWh, $rp) {
    $s = $db->prepare("
        SELECT COUNT(*) FROM incoming_letters il
        LEFT JOIN outgoing_letters ol ON ol.incoming_ref_id = il.id
        WHERE ol.id IS NULL AND il.date < ?{$rWh}
    ");
    $s->execute(array_merge([$deadline], $rp));
    return (int)$s->fetchColumn();
}) ?? 0;

// ── Build response ───────────────────────────────────────────────────────────
$payload = [
    'period'     => ['from' => $fromStr, 'to' => $toStr, 'type' => $period],
    'region_id'  => $regionId,
    'summary'    => [
        'total_incoming'   => $totalIncoming,
        'total_outgoing'   => $totalOutgoing,
        'overdue_letters'  => $overdueLetters,
        'avg_response_days' => $avgResponseDays,
    ],
    'letters_by_region'      => $lettersByRegion,
    'members_by_commission'  => $membersByCommission,
    'top_categories'         => $topCategories,
];

$cache->set($cacheKey, $payload, 3600);
echo json_encode($payload, JSON_ENCODE_FLAGS);
