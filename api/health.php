<?php

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$expectedToken = getenv('HEALTH_CHECK_TOKEN') ?: '';
$providedToken = (string)($_GET['token'] ?? $_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '');

if ($expectedToken !== '') {
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden'], JSON_ENCODE_FLAGS);
        exit;
    }
} else {
    require_once __DIR__ . '/../auth_middleware.php';
    checkAuth();
    requireRole(['admin']);
}

$checks = [
    'database' => false,
    'uploads_writable' => false,
];

$messages = [];

try {
    $db = getDBConnection();
    $db->query('SELECT 1');
    $checks['database'] = true;
} catch (\Throwable $e) {
    $messages[] = 'database: unavailable';
}

$uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : 'uploads/photos/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}
$checks['uploads_writable'] = is_dir($uploadDir) && is_writable($uploadDir);
if (!$checks['uploads_writable']) {
    $messages[] = 'uploads directory is not writable';
}

$rateLimitDir = __DIR__ . '/../.rate_limit';
$checks['rate_limit_dir'] = is_dir($rateLimitDir) || @mkdir($rateLimitDir, 0755, true);

$healthy = $checks['database'] && $checks['uploads_writable'];

http_response_code($healthy ? 200 : 503);
echo json_encode([
    'status' => $healthy ? 'ok' : 'degraded',
    'checks' => $checks,
    'messages' => $messages,
    'timestamp' => date('c'),
], JSON_ENCODE_FLAGS);
