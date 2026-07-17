<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

header('Content-Type: application/json; charset=utf-8');
checkAuth();

$db     = getDBConnection();
$userId = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();

function respondJson(mixed $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_ENCODE_FLAGS);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respondJson(['error' => 'Метод не поддерживается'], 405);
}

$target   = $isAdmin && isset($_GET['user_id']) ? (int)$_GET['user_id'] : $userId;
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset   = ($page - 1) * $limit;
$status   = $_GET['status'] ?? null;

$where  = 'WHERE lh.user_id = ?';
$params = [$target];

if ($status && in_array($status, ['success', 'failed', 'blocked'], true)) {
    $where  .= ' AND lh.status = ?';
    $params[] = $status;
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM login_history lh {$where}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$dataStmt = $db->prepare("
    SELECT lh.id, lh.user_id, lh.username, lh.ip_address, lh.user_agent,
           lh.status, lh.failure_reason, lh.created_at
    FROM login_history lh
    {$where}
    ORDER BY lh.created_at DESC
    LIMIT {$limit} OFFSET {$offset}
");
$dataStmt->execute($params);
$rows = $dataStmt->fetchAll();

// Anomaly detection: flag rows with IPs not seen in prior 30 days
try {
    $since30d = date('Y-m-d H:i:s', strtotime('-30 days'));
    $knownIpStmt = $db->prepare("
        SELECT DISTINCT ip_address
        FROM login_history
        WHERE user_id = ? AND status = 'success'
          AND created_at < ?
        LIMIT 50
    ");
    $knownIpStmt->execute([$target, $since30d]);
    $knownIps = array_column($knownIpStmt->fetchAll(), 'ip_address');
} catch (\Throwable $e) {
    $knownIps = [];
}

foreach ($rows as &$row) {
    $row['is_new_ip'] = !empty($knownIps) && !in_array($row['ip_address'], $knownIps, true);
}
unset($row);

respondJson([
    'items' => $rows,
    'total' => $total,
    'page'  => $page,
    'limit' => $limit,
]);
