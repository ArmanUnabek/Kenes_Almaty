<?php
require_once '../config.php';
require_once '../auth_middleware.php';

$JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

checkAuth();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID не указан'], $JSON_FLAGS);
    exit;
}

$db = getDBConnection();
$stmt = $db->prepare("SELECT file_path, scan_data, file_name, scan_type FROM letter_scans WHERE id = ?");
$stmt->execute([$id]);
$scan = $stmt->fetch();
if (!$scan) {
    http_response_code(404);
    echo json_encode(['error' => 'Файл не найден'], $JSON_FLAGS);
    exit;
}

$contentType = $scan['scan_type'] ?: 'application/octet-stream';
$filename = basename($scan['file_name'] ?: 'scan.bin');

if (!empty($scan['file_path'])) {
    $fullPath = APP_ROOT . '/' . ltrim($scan['file_path'], '/');
    if (is_file($fullPath)) {
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . filesize($fullPath));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        readfile($fullPath);
        exit;
    }
}

if (!empty($scan['scan_data'])) {
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($scan['scan_data']));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $scan['scan_data'];
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Файл не найден'], $JSON_FLAGS);
