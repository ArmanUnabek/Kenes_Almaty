<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Middleware\RateLimiter;
use App\Services\SecurityAuditService;

$JSON_FLAGS = JSON_ENCODE_FLAGS;

checkAuth();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID не указан'], $JSON_FLAGS);
    exit;
}

RateLimiter::requireCheck(
    'scan_dl_user_' . $userId,
    SecurityAuditService::SCAN_DOWNLOAD_RATE_LIMIT,
    SecurityAuditService::SCAN_DOWNLOAD_RATE_WINDOW
);

$db = getDBConnection();
$stmt = $db->prepare("
    SELECT ls.*,
           il.region_id AS incoming_region_id,
           ol.region_id AS outgoing_region_id
    FROM letter_scans ls
    LEFT JOIN incoming_letters il ON ls.letter_type = 'incoming' AND ls.letter_id = il.id
    LEFT JOIN outgoing_letters ol ON ls.letter_type = 'outgoing' AND ls.letter_id = ol.id
    WHERE ls.id = ?
");
$stmt->execute([$id]);
$scan = $stmt->fetch();
if (!$scan) {
    http_response_code(404);
    echo json_encode(['error' => 'Файл не найден'], $JSON_FLAGS);
    exit;
}

$letterType = (string)($scan['letter_type'] ?? '');
$letterId = (int)($scan['letter_id'] ?? 0);
$regionId = $letterType === 'incoming'
    ? (int)($scan['incoming_region_id'] ?? 0)
    : (int)($scan['outgoing_region_id'] ?? 0);

if ($regionId <= 0 || !canAccessRegion($regionId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ к файлу запрещён'], $JSON_FLAGS);
    exit;
}

// Sanitize filename to prevent header injection
$filename = preg_replace('/[^\w.\-]/', '_', basename($scan['file_name'] ?: 'scan.bin'));
SecurityAuditService::logScanDownload(
    $db,
    $userId,
    $regionId,
    $id,
    $letterType,
    $letterId,
    $filename
);

// Whitelist allowed content types from DB to prevent stored XSS via Content-Type
$allowedContentTypes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'image/tiff', 'image/bmp', 'application/pdf', 'application/octet-stream',
];
$rawContentType = $scan['scan_type'] ?: 'application/octet-stream';
$contentType = in_array($rawContentType, $allowedContentTypes, true)
    ? $rawContentType
    : 'application/octet-stream';

$inline = isset($_GET['inline']) && $_GET['inline'] === '1';
$allowedInline = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
if ($inline && !in_array($contentType, $allowedInline, true)) {
    $inline = false;
}
$disposition = ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"';

if (!empty($scan['file_path'])) {
    $uploadsRoot = realpath(APP_ROOT . '/uploads');
    $fullPath = realpath(APP_ROOT . '/' . ltrim((string)$scan['file_path'], '/'));
    if (!$uploadsRoot || !$fullPath || !str_starts_with($fullPath, $uploadsRoot . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        echo json_encode(['error' => 'Файл не найден'], $JSON_FLAGS);
        exit;
    }
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($fullPath));
    header('Content-Disposition: ' . $disposition);
    header('X-Content-Type-Options: nosniff');
    readfile($fullPath);
    exit;
}

if (!empty($scan['scan_data'])) {
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($scan['scan_data']));
    header('Content-Disposition: ' . $disposition);
    header('X-Content-Type-Options: nosniff');
    echo $scan['scan_data'];
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Файл не найден'], $JSON_FLAGS);
