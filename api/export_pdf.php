<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

checkAuth();
$db = getDBConnection();
$type = $_GET['type'] ?? 'summary';
$regionId = getCurrentRegionId();

$regionClause = $regionId ? ' WHERE region_id = ?' : '';
$params = $regionId ? [$regionId] : [];

$incomingCount = 0;
$outgoingCount = 0;
$pendingCount = 0;

$stmt = $db->prepare('SELECT COUNT(*) FROM incoming_letters' . $regionClause);
$stmt->execute($params);
$incomingCount = (int)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM outgoing_letters' . $regionClause);
$stmt->execute($params);
$outgoingCount = (int)$stmt->fetchColumn();

$sqlPending = '
    SELECT COUNT(*) FROM incoming_letters il
    LEFT JOIN outgoing_letters ol ON ol.incoming_ref_id = il.id
    WHERE ol.id IS NULL' . ($regionId ? ' AND il.region_id = ?' : '');
$stmt = $db->prepare($sqlPending);
$stmt->execute($params);
$pendingCount = (int)$stmt->fetchColumn();

$regionName = 'Все регионы';
if ($regionId) {
    $stmt = $db->prepare('SELECT name_ru FROM regions WHERE id = ?');
    $stmt->execute([$regionId]);
    $regionName = (string)($stmt->fetchColumn() ?: 'Регион');
}

$html = '<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#111}
h1{font-size:18px;margin-bottom:4px} h2{font-size:14px;margin-top:20px}
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{border:1px solid #ccc;padding:6px;text-align:left}
th{background:#f4f6fb}
.meta{color:#666;font-size:11px}
</style></head><body>';
$html .= '<h1>Журнал Общественного Совета</h1>';
$html .= '<p class="meta">Отчёт: ' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($regionName, ENT_QUOTES, 'UTF-8') . ' · ' . date('d.m.Y H:i') . '</p>';
$html .= '<table><tr><th>Показатель</th><th>Значение</th></tr>';
$html .= '<tr><td>Входящие письма</td><td>' . $incomingCount . '</td></tr>';
$html .= '<tr><td>Исходящие письма</td><td>' . $outgoingCount . '</td></tr>';
$html .= '<tr><td>Без ответа</td><td>' . $pendingCount . '</td></tr>';
$html .= '</table>';

if ($type === 'kpi') {
    $html .= '<h2>Топ организаций (входящие)</h2><table><tr><th>Организация</th><th>Кол-во</th></tr>';
    $sql = 'SELECT organization, COUNT(*) AS cnt FROM incoming_letters' . $regionClause . ' GROUP BY organization ORDER BY cnt DESC LIMIT 10';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $html .= '<tr><td>' . htmlspecialchars((string)$row['organization'], ENT_QUOTES, 'UTF-8') . '</td><td>' . (int)$row['cnt'] . '</td></tr>';
    }
    $html .= '</table>';
}

$html .= '</body></html>';

if (class_exists('\\Mpdf\\Mpdf')) {
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4']);
    $mpdf->WriteHTML($html);
    $mpdf->Output('os_journal_report.pdf', \Mpdf\Output\Destination::DOWNLOAD);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="os_journal_report.html"');
echo $html;
