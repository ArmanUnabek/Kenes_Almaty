<?php
/**
 * One-time script to register the Telegram webhook URL.
 * Run once after deployment:
 *   GET /api/telegram_setup.php?token=<CRON_TOKEN>
 * or from CLI:
 *   php api/telegram_setup.php
 */

require_once __DIR__ . '/../config.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $expectedToken = envValue('CRON_TOKEN');
    $providedToken = $_GET['token'] ?? '';
    if (!is_string($expectedToken) || $expectedToken === ''
        || !is_string($providedToken)
        || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещён'], JSON_ENCODE_FLAGS);
        exit;
    }
}

$botToken      = defined('TELEGRAM_BOT_TOKEN')      ? TELEGRAM_BOT_TOKEN      : '';
$webhookSecret = defined('TELEGRAM_WEBHOOK_SECRET') ? TELEGRAM_WEBHOOK_SECRET : '';
$appUrl        = defined('APP_URL')                 ? rtrim(APP_URL, '/')     : '';

if ($botToken === '') {
    $msg = ['error' => 'TELEGRAM_BOT_TOKEN не настроен'];
    if ($isCli) { fwrite(STDERR, $msg['error'] . PHP_EOL); exit(1); }
    http_response_code(400);
    echo json_encode($msg, JSON_ENCODE_FLAGS);
    exit;
}

if ($webhookSecret === '') {
    $msg = ['error' => 'TELEGRAM_WEBHOOK_SECRET не настроен (нужен для безопасности)'];
    if ($isCli) { fwrite(STDERR, $msg['error'] . PHP_EOL); exit(1); }
    http_response_code(400);
    echo json_encode($msg, JSON_ENCODE_FLAGS);
    exit;
}

if ($appUrl === '') {
    $msg = ['error' => 'APP_URL не настроен'];
    if ($isCli) { fwrite(STDERR, $msg['error'] . PHP_EOL); exit(1); }
    http_response_code(400);
    echo json_encode($msg, JSON_ENCODE_FLAGS);
    exit;
}

$webhookUrl = $appUrl . '/api/telegram_webhook.php';
$apiUrl     = "https://api.telegram.org/bot{$botToken}/setWebhook";

$body = json_encode([
    'url'          => $webhookUrl,
    'secret_token' => $webhookSecret,
    'allowed_updates' => ['message'],
]);

$context = stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n",
        'content'       => $body,
        'timeout'       => 10,
        'ignore_errors' => true,
    ],
]);

$resp = @file_get_contents($apiUrl, false, $context);
if ($resp === false) {
    $msg = ['error' => 'Не удалось подключиться к Telegram API'];
    if ($isCli) { fwrite(STDERR, $msg['error'] . PHP_EOL); exit(1); }
    http_response_code(502);
    echo json_encode($msg, JSON_ENCODE_FLAGS);
    exit;
}

$result = json_decode($resp, true);

if ($isCli) {
    if ($result['ok'] ?? false) {
        echo '[OK] Webhook зарегистрирован: ' . $webhookUrl . PHP_EOL;
        echo '[OK] ' . ($result['description'] ?? '') . PHP_EOL;
    } else {
        fwrite(STDERR, '[ERROR] ' . ($result['description'] ?? $resp) . PHP_EOL);
        exit(1);
    }
} else {
    echo json_encode([
        'webhook_url' => $webhookUrl,
        'telegram'    => $result,
    ], JSON_ENCODE_FLAGS);
}
