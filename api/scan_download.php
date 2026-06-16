<?php
require_once '../config.php';
require_once '../auth_middleware.php';

checkAuth();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID не указан'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = getDBConnection();
$stmt = $db->prepare("SELECT file_path, file_name, scan_type FROM letter_scans WHERE id = ?");
$stmt->execute([$id]);
$scan = $stmt->fetch();
if (!$scan || empty($scan['file_path'])) {
    http_response_code(404);
    echo json_encode(['error' => 'Файл не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fullPath = APP_ROOT . '/' . ltrim($scan['file_path'], '/');
if (!is_file($fullPath)) {
    http_response_code(404);
    echo json_encode(['error' => 'Файл не найден'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: ' . ($scan['scan_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: attachment; filename="' . basename($scan['file_name'] ?: 'scan.bin') . '"');
readfile($fullPath);

