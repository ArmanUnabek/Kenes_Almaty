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
$stmt = $db->prepare("
    SELECT al.*, u.full_name AS user_name, u.username AS user_login
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    ORDER BY al.id DESC
    LIMIT ? OFFSET ?
");
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
], JSON_ENCODE_FLAGS);

