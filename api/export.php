<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Middleware\RateLimiter;
use App\Services\SecurityAuditService;

checkAuth();
requireRole(['admin']);

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$format = strtolower((string)($_GET['format'] ?? 'json'));
if (!in_array($format, ['json', 'csv'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Формат должен быть json или csv'], JSON_ENCODE_FLAGS);
    exit;
}

RateLimiter::requireCheck(
    'export_user_' . $userId,
    SecurityAuditService::EXPORT_RATE_LIMIT,
    SecurityAuditService::EXPORT_RATE_WINDOW
);

$db = getDBConnection();
$regionId = getCurrentRegionId();
$regionClause = $regionId ? ' WHERE region_id = ?' : '';
$params = $regionId ? [$regionId] : [];

$stmt = $db->prepare('SELECT * FROM incoming_letters' . $regionClause . ' ORDER BY date DESC, seq DESC');
$stmt->execute($params);
$incoming = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM outgoing_letters' . $regionClause . ' ORDER BY date DESC, seq DESC');
$stmt->execute($params);
$outgoing = $stmt->fetchAll();

SecurityAuditService::logExport(
    $db,
    $userId,
    $regionId,
    $format,
    count($incoming),
    count($outgoing)
);

$date = date('Y-m-d');
$filename = 'os_journal_' . $date;

if ($format === 'json') {
    $payload = [
        'exported_at' => date('c'),
        'exported_by' => $user['username'] ?? null,
        'region_id' => $regionId,
        'incoming' => $incoming,
        'outgoing' => $outgoing,
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
    echo json_encode($payload, JSON_ENCODE_FLAGS | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF");

fputcsv($out, ['Тип', 'РегНомер', 'Дата', 'Организация', 'Категория', 'Номер', 'Тема', 'Примечание'], ';');
foreach ($incoming as $row) {
    fputcsv($out, [
        'Входящее',
        'Вх.' . ($row['seq'] ?? ''),
        $row['date'] ?? '',
        $row['organization'] ?? '',
        $row['category'] ?? 'KK',
        $row['kk_number'] ?? '',
        $row['subject'] ?? '',
        $row['note'] ?? '',
    ], ';');
}

fputcsv($out, [], ';');
fputcsv($out, ['Тип', 'Порядк№', 'Дата', 'Исходящий№', 'Организация', 'Тема', 'Примечание'], ';');
foreach ($outgoing as $row) {
    fputcsv($out, [
        'Исходящее',
        'Исх.' . ($row['seq'] ?? ''),
        $row['date'] ?? '',
        $row['outgoing_number'] ?? '',
        $row['organization'] ?? '',
        $row['subject'] ?? '',
        $row['note'] ?? '',
    ], ';');
}
fclose($out);
