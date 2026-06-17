<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

header('Content-Type: application/json; charset=utf-8');

checkAuth();
requireRole(['admin']);

$db = getDBConnection();

$stats = [
    'regions' => ['total' => 0, 'active' => 0, 'inactive' => 0],
    'users' => ['total' => 0, 'active' => 0, 'inactive' => 0, 'by_role' => []],
    'members' => 0,
    'letters' => ['incoming' => 0, 'outgoing' => 0],
    'audit' => ['total' => 0, 'last_24h' => 0, 'security_events' => 0],
    'email_queue' => ['pending' => 0, 'sent' => 0, 'failed' => 0, 'total' => 0],
];

$row = $db->query('SELECT COUNT(*) AS total, SUM(is_active = 1) AS active FROM regions')->fetch();
$stats['regions']['total'] = (int)($row['total'] ?? 0);
$stats['regions']['active'] = (int)($row['active'] ?? 0);
$stats['regions']['inactive'] = $stats['regions']['total'] - $stats['regions']['active'];

$row = $db->query('SELECT COUNT(*) AS total, SUM(is_active = 1) AS active FROM users')->fetch();
$stats['users']['total'] = (int)($row['total'] ?? 0);
$stats['users']['active'] = (int)($row['active'] ?? 0);
$stats['users']['inactive'] = $stats['users']['total'] - $stats['users']['active'];

$roleRows = $db->query('SELECT role, COUNT(*) AS cnt FROM users WHERE is_active = 1 GROUP BY role')->fetchAll();
foreach ($roleRows as $r) {
    $stats['users']['by_role'][$r['role']] = (int)$r['cnt'];
}

$stats['members'] = (int)$db->query("SELECT COUNT(*) FROM os_members WHERE status = 'active'")->fetchColumn();
$stats['letters']['incoming'] = (int)$db->query('SELECT COUNT(*) FROM incoming_letters')->fetchColumn();
$stats['letters']['outgoing'] = (int)$db->query('SELECT COUNT(*) FROM outgoing_letters')->fetchColumn();

$stats['audit']['total'] = (int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$stats['audit']['last_24h'] = (int)$db->query('SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)')->fetchColumn();
$stats['audit']['security_events'] = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE operation IN ('EXPORT', 'DOWNLOAD')")->fetchColumn();

try {
    $eq = $db->query("SELECT status, COUNT(*) AS cnt FROM email_queue GROUP BY status")->fetchAll();
    foreach ($eq as $e) {
        $status = strtolower((string)$e['status']);
        $cnt = (int)$e['cnt'];
        if (isset($stats['email_queue'][$status])) {
            $stats['email_queue'][$status] = $cnt;
        }
        $stats['email_queue']['total'] += $cnt;
    }
} catch (\Throwable $e) {
    // email_queue table may not exist on older installs
}

$uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : 'uploads/photos/';
$health = [
    'database' => true,
    'uploads_writable' => is_dir($uploadDir) && is_writable($uploadDir),
    'status' => 'ok',
];
if (!$health['uploads_writable']) {
    $health['status'] = 'degraded';
}

echo json_encode([
    'stats' => $stats,
    'health' => $health,
    'timestamp' => date('c'),
], JSON_ENCODE_FLAGS);
