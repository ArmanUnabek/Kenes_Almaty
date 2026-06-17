<?php
require_once '../config.php';
require_once '../auth_middleware.php';

checkAuth();
requireRole(['admin']);

$db = getDBConnection();
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(500, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;
$securityOnly = isset($_GET['security']) && $_GET['security'] === '1';
$regionFilter = isset($_GET['region_id']) ? (int)$_GET['region_id'] : 0;
$search = trim((string)($_GET['search'] ?? ''));
$format = strtolower((string)($_GET['format'] ?? 'json'));

$conditions = [];
$params = [];

if ($securityOnly) {
    $conditions[] = "al.operation IN ('EXPORT', 'DOWNLOAD')";
}
if ($regionFilter > 0) {
    $conditions[] = 'al.region_id = ?';
    $params[] = $regionFilter;
}
if ($search !== '') {
    $conditions[] = '(al.table_name LIKE ? OR al.operation LIKE ? OR u.full_name LIKE ? OR u.username LIKE ? OR CAST(al.record_id AS CHAR) LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$exportLimit = $format === 'csv' ? min(5000, max(1, (int)($_GET['limit'] ?? 2000))) : $limit;
$exportOffset = $format === 'csv' ? 0 : $offset;

$stmt = $db->prepare("
    SELECT al.*, u.full_name AS user_name, u.username AS user_login
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $where
    ORDER BY al.id DESC
    LIMIT ? OFFSET ?
");
$bindIndex = 1;
foreach ($params as $param) {
    $stmt->bindValue($bindIndex++, $param);
}
$stmt->bindValue($bindIndex++, $exportLimit, PDO::PARAM_INT);
$stmt->bindValue($bindIndex, $exportOffset, PDO::PARAM_INT);
$stmt->execute();

$items = array_map(static function (array $row): array {
    foreach (['old_values', 'new_values'] as $key) {
        if (!isset($row[$key]) || $row[$key] === null || $row[$key] === '') {
            continue;
        }
        if (is_string($row[$key])) {
            $decoded = json_decode($row[$key], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row[$key] = $decoded;
            }
        }
    }
    if (!isset($row['operation']) && isset($row['action'])) {
        $row['operation'] = $row['action'];
    }
    if (!isset($row['record_id']) && isset($row['entity_id'])) {
        $row['record_id'] = $row['entity_id'];
    }
    if (!isset($row['old_values']) && isset($row['old_data'])) {
        $row['old_values'] = $row['old_data'];
    }
    if (!isset($row['new_values']) && isset($row['new_data'])) {
        $row['new_values'] = $row['new_data'];
    }
    return $row;
}, $stmt->fetchAll());

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['id', 'created_at', 'user_name', 'user_login', 'table_name', 'operation', 'record_id', 'region_id', 'ip_address'], ';');
    foreach ($items as $row) {
        fputcsv($out, [
            $row['id'] ?? '',
            $row['created_at'] ?? '',
            $row['user_name'] ?? '',
            $row['user_login'] ?? '',
            $row['table_name'] ?? '',
            $row['operation'] ?? '',
            $row['record_id'] ?? '',
            $row['region_id'] ?? '',
            $row['ip_address'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

echo json_encode([
    'items' => $items,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => (int)ceil($total / max(1, $limit)),
    ],
], JSON_ENCODE_FLAGS);
