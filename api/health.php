<?php

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$expectedToken = getenv('HEALTH_CHECK_TOKEN') ?: '';
// Token must be sent as X-Health-Token header — never in the URL (would appear in access logs)
$providedToken = (string)($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '');

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
$metrics  = [];

try {
    $db = getDBConnection();
    $db->query('SELECT 1');
    $checks['database'] = true;
} catch (\Throwable $e) {
    $messages[] = 'database: unavailable';
}

$uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (APP_ROOT . '/uploads/photos/');
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}
$checks['uploads_writable'] = is_dir($uploadDir) && is_writable($uploadDir);
if (!$checks['uploads_writable']) {
    $messages[] = 'uploads directory is not writable';
}

$rateLimitDir = __DIR__ . '/../.rate_limit';
$checks['rate_limit_dir'] = is_dir($rateLimitDir) || @mkdir($rateLimitDir, 0755, true);

// ── Extended metrics ──────────────────────────────────────────────────────────

// PHP memory
$metrics['php_memory_mb']       = round(memory_get_usage(true) / 1048576, 2);
$metrics['php_memory_limit']    = ini_get('memory_limit');
$metrics['php_version']         = PHP_VERSION;

// Uploads directory size
function dirSizeBytes(string $dir): int {
    $size = 0;
    if (!is_dir($dir)) return 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) $size += $f->getSize();
    }
    return $size;
}
$uploadsBytes = dirSizeBytes($uploadDir);
$metrics['uploads_size_mb'] = round($uploadsBytes / 1048576, 2);
$metrics['uploads_files']   = iterator_count(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS))
);

// SMTP check: connect and verify the 220 greeting banner
$smtpHost = SMTP_HOST;
$smtpPort = SMTP_PORT ?: 587;
if ($smtpHost) {
    $checks['smtp_reachable'] = false;
    $sock = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 3);
    if ($sock) {
        stream_set_timeout($sock, 3);
        $banner = fgets($sock, 512);
        fwrite($sock, "QUIT\r\n");
        fclose($sock);
        // A valid SMTP server responds with "220 ..."
        if ($banner !== false && str_starts_with(ltrim($banner), '220')) {
            $checks['smtp_reachable'] = true;
        } else {
            $checks['smtp_reachable'] = false;
            $messages[] = 'smtp: connected but no valid 220 greeting';
        }
    } else {
        $messages[] = 'smtp: connection failed';
    }
} else {
    $checks['smtp_configured'] = false;
    $messages[] = 'smtp: not configured';
}

// Pusher check (config present?)
$checks['pusher_configured'] = (bool)(PUSHER_APP_ID && PUSHER_KEY && PUSHER_SECRET);

// Email queue stats
if ($checks['database']) {
    try {
        $qStmt = $db->query("SELECT status, COUNT(*) AS cnt FROM email_queue GROUP BY status");
        $queueStats = [];
        foreach ($qStmt->fetchAll() as $row) {
            $queueStats[$row['status']] = (int)$row['cnt'];
        }
        $metrics['email_queue'] = $queueStats;
        $metrics['email_queue_pending'] = ($queueStats['queued'] ?? 0) + ($queueStats['failed'] ?? 0);
    } catch (\Throwable $e) {
        // email_queue table may not exist
    }

    // DB table count as a size proxy
    try {
        $dbStmt = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
        $metrics['db_table_count'] = (int)$dbStmt->fetchColumn();
    } catch (\Throwable $e) {
        // SQLite or other DBs without information_schema
    }
}

// Cron last run (check for timestamp file)
$cronStamp = APP_ROOT . '/.cron_last_run';
if (file_exists($cronStamp)) {
    $metrics['cron_last_run'] = date('c', (int)file_get_contents($cronStamp));
} else {
    $metrics['cron_last_run'] = null;
}

$healthy = $checks['database'] && $checks['uploads_writable'];

http_response_code($healthy ? 200 : 503);
echo json_encode([
    'status'   => $healthy ? 'ok' : 'degraded',
    'checks'   => $checks,
    'metrics'  => $metrics,
    'messages' => $messages,
    'timestamp' => date('c'),
], JSON_ENCODE_FLAGS);
