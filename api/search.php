<?php
require_once '../config.php';
require_once '../auth_middleware.php';

checkAuth();
$db = getDBConnection();
$q = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));

if ($q === '') {
    echo json_encode(['items' => []], JSON_ENCODE_FLAGS);
    exit;
}

$like = '%' . $q . '%';
$stmt = $db->prepare("
    SELECT 'incoming' AS source, id, date, organization, subject, note
    FROM incoming_letters
    WHERE subject LIKE ? OR note LIKE ? OR organization LIKE ?
    UNION ALL
    SELECT 'outgoing' AS source, id, date, organization, subject, note
    FROM outgoing_letters
    WHERE subject LIKE ? OR note LIKE ? OR organization LIKE ?
    ORDER BY date DESC
    LIMIT ?
");
$stmt->bindValue(1, $like, PDO::PARAM_STR);
$stmt->bindValue(2, $like, PDO::PARAM_STR);
$stmt->bindValue(3, $like, PDO::PARAM_STR);
$stmt->bindValue(4, $like, PDO::PARAM_STR);
$stmt->bindValue(5, $like, PDO::PARAM_STR);
$stmt->bindValue(6, $like, PDO::PARAM_STR);
$stmt->bindValue(7, $limit, PDO::PARAM_INT);
$stmt->execute();

echo json_encode(['items' => $stmt->fetchAll()], JSON_ENCODE_FLAGS);

