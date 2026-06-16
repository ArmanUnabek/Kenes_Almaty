<?php
require_once '../config.php';
require_once '../auth_middleware.php';

checkAuth();
requireRole(['admin']);

$db = getDBConnection();
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$total = (int)$db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$stmt = $db->prepare("SELECT * FROM audit_logs ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();

echo json_encode([
    'items' => $stmt->fetchAll(),
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => (int)ceil($total / max(1, $limit))
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

