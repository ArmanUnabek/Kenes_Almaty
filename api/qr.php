<?php
/**
 * GET /api/qr.php?data=...&size=200
 * Возвращает QR-код в формате SVG (генерируется локально).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/Services/QrService.php';

use App\Middleware\RateLimiter;
use App\Services\QrService;

checkAuth();
// Ключ обязан быть per-user: строковый ключ без суффикса — общий bucket на всех
RateLimiter::requireCheck('qr_' . (int)($_SESSION['user_id'] ?? 0), 120, 3600);

$data = $_GET['data'] ?? '';
$size = (int)($_GET['size'] ?? 200);

if ($data === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Параметр data обязателен'], JSON_ENCODE_FLAGS);
    exit;
}

// Ёмкость QR ограничена (~2900 байт в byte-режиме при ECC M) — при превышении
// библиотека бросит исключение; отвечаем 400, а не 500.
if (strlen($data) > 2300) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Слишком длинные данные для QR-кода (макс. 2300 байт)'], JSON_ENCODE_FLAGS);
    exit;
}

$size = max(50, min(1000, $size));

$svg = QrService::generateQrSvg($data, $size);

if ($svg === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Не удалось сгенерировать QR-код'], JSON_ENCODE_FLAGS);
    exit;
}

header('Content-Type: image/svg+xml; charset=utf-8');
header('Content-Length: ' . strlen($svg));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $svg;
