<?php
require_once '../config.php';
require_once '../auth_middleware.php';

use App\Services\EmailService;

requireRole(['admin', 'moderator']);
$db = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $to = trim((string)($data['to'] ?? ''));
    $subject = trim((string)($data['subject'] ?? 'Уведомление ОС'));
    $body = (string)($data['body_html'] ?? '');
    if ($to === '' || $body === '') {
        http_response_code(400);
        echo json_encode(['error' => 'to и body_html обязательны'], JSON_ENCODE_FLAGS);
        exit;
    }
    EmailService::enqueue($db, $to, $subject, $body, strip_tags($body));
    echo json_encode(['message' => 'Письмо поставлено в очередь'], JSON_ENCODE_FLAGS);
    exit;
}

$rows = $db->query("SELECT * FROM email_queue ORDER BY id DESC LIMIT 100")->fetchAll();
echo json_encode(['items' => $rows], JSON_ENCODE_FLAGS);

