<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../src/Middleware/RateLimiter.php';
require_once __DIR__ . '/../src/Services/KazLlmTranslationService.php';

use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimiter;
use App\Services\KazLlmTranslationService;

header('Content-Type: application/json; charset=utf-8');

checkAuth();
requireRole(['admin', 'moderator']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'status';
    if ($action === 'status') {
        echo json_encode([
            'enabled' => KazLlmTranslationService::isEnabled(),
            'ping' => KazLlmTranslationService::ping(),
        ], JSON_ENCODE_FLAGS);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Неизвестное действие'], JSON_ENCODE_FLAGS);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не поддерживается'], JSON_ENCODE_FLAGS);
    exit;
}

CsrfMiddleware::requireVerification();
RateLimiter::requireCheck('kazllm_translate_' . RateLimiter::getIdentifier(), 30, 3600);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$text = trim((string)($data['text'] ?? ''));
$source = strtolower(trim((string)($data['source'] ?? 'ru')));
$target = strtolower(trim((string)($data['target'] ?? 'kk')));
if ($target === 'kz') {
    $target = 'kk';
}

if ($text === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Поле text обязательно'], JSON_ENCODE_FLAGS);
    exit;
}

if (!in_array($source, ['ru', 'kk', 'kz'], true) || !in_array($target, ['ru', 'kk', 'kz'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Неподдерживаемая пара языков'], JSON_ENCODE_FLAGS);
    exit;
}

try {
    $db = getDBConnection();
    $result = KazLlmTranslationService::translate($db, $text, $source, $target);
    echo json_encode([
        'text' => $result['text'],
        'cached' => $result['cached'],
        'source' => $source,
        'target' => $target,
    ], JSON_ENCODE_FLAGS);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_ENCODE_FLAGS);
} catch (\Throwable $e) {
    error_log('translate.php: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Сервис перевода временно недоступен'], JSON_ENCODE_FLAGS);
}
