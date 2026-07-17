<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Middleware\RateLimiter;
use App\Services\SecurityAuditService;

checkAuth();
requireRole(['admin']);

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

RateLimiter::requireCheck(
    'export_pdf_user_' . $userId,
    SecurityAuditService::EXPORT_RATE_LIMIT,
    SecurityAuditService::EXPORT_RATE_WINDOW
);

$db = getDBConnection();
$type = $_GET['type'] ?? 'summary';

// Регион: endpoint только для admin — допускаем явный region_id
// (>0 = конкретный регион, 'all' = все регионы, иначе активный регион из сессии).
$regionId = getCurrentRegionId();
$requestedRegion = $_GET['region_id'] ?? '';
if ($requestedRegion === 'all') {
    $regionId = null;
} elseif ((int)$requestedRegion > 0) {
    $regionId = (int)$requestedRegion;
}

// Optional date range filter (YYYY-MM-DD)
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';
if ($dateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) { $dateFrom = ''; }
if ($dateTo   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   { $dateTo   = ''; }

$conditions = [];
$params     = [];
if ($regionId)  { $conditions[] = 'region_id = ?'; $params[] = $regionId; }
if ($dateFrom)  { $conditions[] = 'date >= ?';     $params[] = $dateFrom; }
if ($dateTo)    { $conditions[] = 'date <= ?';     $params[] = $dateTo; }
$whereClause = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

$stmt = $db->prepare('SELECT COUNT(*) FROM incoming_letters' . $whereClause);
$stmt->execute($params);
$incomingCount = (int)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM outgoing_letters' . $whereClause);
$stmt->execute($params);
$outgoingCount = (int)$stmt->fetchColumn();

$pendingConditions = [];
$pendingParams = [];
if ($regionId) { $pendingConditions[] = 'il.region_id = ?'; $pendingParams[] = $regionId; }
if ($dateFrom) { $pendingConditions[] = 'il.date >= ?';     $pendingParams[] = $dateFrom; }
if ($dateTo)   { $pendingConditions[] = 'il.date <= ?';     $pendingParams[] = $dateTo; }
$pendingWhere = $pendingConditions
    ? ' AND ' . implode(' AND ', $pendingConditions)
    : '';
$sqlPending = 'SELECT COUNT(*) FROM incoming_letters il
    LEFT JOIN outgoing_letters ol ON ol.incoming_ref_id = il.id
    WHERE ol.id IS NULL' . $pendingWhere;
$stmt = $db->prepare($sqlPending);
$stmt->execute($pendingParams);
$pendingCount = (int)$stmt->fetchColumn();

SecurityAuditService::logExport($db, $userId, $regionId, 'pdf_' . $type, $incomingCount, $outgoingCount);

$regionName = 'Все регионы';
if ($regionId) {
    $stmt = $db->prepare('SELECT name_ru FROM regions WHERE id = ?');
    $stmt->execute([$regionId]);
    $regionName = (string)($stmt->fetchColumn() ?: 'Регион');
}

$watermark = htmlspecialchars(
    'Конфиденциально · ' . ($user['full_name'] ?? $user['username'] ?? 'admin') . ' · ' . date('d.m.Y H:i'),
    ENT_QUOTES,
    'UTF-8'
);

$html = '<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#111}
h1{font-size:18px;margin-bottom:4px} h2{font-size:14px;margin-top:20px}
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{border:1px solid #ccc;padding:6px;text-align:left}
th{background:#f4f6fb}
.meta{color:#666;font-size:11px}
.watermark{color:#999;font-size:10px;margin-top:24px;border-top:1px dashed #ccc;padding-top:8px}
</style></head><body>';
$html .= '<h1>Журнал Общественного Совета</h1>';
$dateRangeLabel = '';
if ($dateFrom || $dateTo) {
    $dateRangeLabel = ' · ' . ($dateFrom ? date('d.m.Y', strtotime($dateFrom)) : '…') . ' — ' . ($dateTo ? date('d.m.Y', strtotime($dateTo)) : '…');
}
$html .= '<p class="meta">Отчёт: ' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($regionName, ENT_QUOTES, 'UTF-8') . $dateRangeLabel . ' · ' . date('d.m.Y H:i') . '</p>';
$html .= '<table><tr><th>Показатель</th><th>Значение</th></tr>';
$html .= '<tr><td>Входящие письма</td><td>' . $incomingCount . '</td></tr>';
$html .= '<tr><td>Исходящие письма</td><td>' . $outgoingCount . '</td></tr>';
$html .= '<tr><td>Без ответа</td><td>' . $pendingCount . '</td></tr>';
$html .= '</table>';

if ($type === 'kpi') {
    $html .= '<h2>Топ организаций (входящие)</h2><table><tr><th>Организация</th><th>Кол-во</th></tr>';
    $sql = 'SELECT organization, COUNT(*) AS cnt FROM incoming_letters' . $whereClause . ' GROUP BY organization ORDER BY cnt DESC LIMIT 10';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $html .= '<tr><td>' . htmlspecialchars((string)$row['organization'], ENT_QUOTES, 'UTF-8') . '</td><td>' . (int)$row['cnt'] . '</td></tr>';
    }
    $html .= '</table>';
}

$html .= '<p class="watermark">' . $watermark . '</p>';
$html .= '</body></html>';

if (class_exists('\\Mpdf\\Mpdf')) {
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4']);
    $mpdf->SetWatermarkText($user['full_name'] ?? 'Журнал ОС', 0.08);
    $mpdf->showWatermarkText = true;
    $mpdf->WriteHTML($html);
    $mpdf->Output('os_journal_report.pdf', \Mpdf\Output\Destination::DOWNLOAD);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="os_journal_report.html"');
echo $html;
