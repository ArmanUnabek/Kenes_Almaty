<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Middleware\CsrfMiddleware;
use App\Services\EmailService;

header('Content-Type: application/json; charset=utf-8');

checkAuth();
requireRole(['admin', 'moderator']);

$db = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    CsrfMiddleware::requireVerification();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $to = trim((string)($data['to'] ?? ''));
    $subject = trim((string)($data['subject'] ?? 'Уведомление ОС'));
    $body = (string)($data['body_html'] ?? '');
    if ($to === '' || $body === '') {
        http_response_code(400);
        echo json_encode(['error' => 'to и body_html обязательны'], JSON_ENCODE_FLAGS);
        exit;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['error' => 'Некорректный email'], JSON_ENCODE_FLAGS);
        exit;
    }
    EmailService::enqueue($db, $to, $subject, $body, strip_tags($body));
    echo json_encode(['message' => 'Письмо поставлено в очередь'], JSON_ENCODE_FLAGS);
    exit;
}

$limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
$stmt = $db->prepare('SELECT id, recipient_email, subject, status, created_at, sent_at FROM email_queue ORDER BY id DESC LIMIT ?');
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

echo json_encode(['items' => $items], JSON_ENCODE_FLAGS);
