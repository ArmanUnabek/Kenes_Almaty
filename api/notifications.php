<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Middleware\CsrfMiddleware;
use App\Services\EmailService;
use App\Middleware\RateLimiter;
use App\Services\NotificationRecipientPolicy;

header('Content-Type: application/json; charset=utf-8');

checkAuth();
requireRole(['admin', 'moderator']);

$db = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'PATCH') {
    CsrfMiddleware::requireVerification();
    requireRole(['admin']);
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($data['id'] ?? 0);
    $action = $data['action'] ?? '';
    if ($id <= 0 || $action !== 'retry') {
        http_response_code(400);
        echo json_encode(['error' => 'id и action=retry обязательны'], JSON_ENCODE_FLAGS);
        exit;
    }
    $stmt = $db->prepare("UPDATE email_queue SET status = 'queued', error = NULL, sent_at = NULL WHERE id = ? AND status = 'failed'");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Запись не найдена или статус не failed'], JSON_ENCODE_FLAGS);
        exit;
    }
    echo json_encode(['message' => 'Письмо поставлено в очередь повторно'], JSON_ENCODE_FLAGS);
    exit;
}

if ($method === 'POST') {
    RateLimiter::requireCheck('notify_email_' . RateLimiter::getIdentifier(), 10, 3600);
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
    NotificationRecipientPolicy::assertAllowed($db, $to);
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
