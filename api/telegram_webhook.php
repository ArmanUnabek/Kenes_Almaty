<?php
/**
 * Telegram Bot Webhook Handler
 *
 * When a user sends /start or /chatid, the bot replies with their Chat ID.
 * The admin then saves that Chat ID in the user's profile to enable notifications.
 *
 * Setup (one-time, admin only):
 *   GET /api/telegram_webhook.php?action=register
 *   This will register the webhook URL with Telegram automatically.
 *
 * Manual setup:
 *   curl "https://api.telegram.org/bot{TOKEN}/setWebhook" \
 *        -d "url=https://YOURSITE/api/telegram_webhook.php" \
 *        -d "secret_token={TELEGRAM_WEBHOOK_SECRET}"
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Services\TelegramService;

if (!TelegramService::isConfigured()) {
    http_response_code(404);
    exit;
}

$webhookSecret = defined('TELEGRAM_WEBHOOK_SECRET') && TELEGRAM_WEBHOOK_SECRET !== ''
    ? TELEGRAM_WEBHOOK_SECRET
    : substr(hash('sha256', TELEGRAM_BOT_TOKEN), 0, 32);

// Admin-only: register webhook with Telegram
if (($_GET['action'] ?? '') === 'register') {
    checkAuth();
    requireRole(['admin']);

    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $webhookUrl = $https . '://' . $host . '/api/telegram_webhook.php';

    $token = TELEGRAM_BOT_TOKEN;
    $body  = json_encode([
        'url'             => $webhookUrl,
        'secret_token'    => $webhookSecret,
        'allowed_updates' => ['message'],
    ]);

    $ctx  = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $body,
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents("https://api.telegram.org/bot{$token}/setWebhook", false, $ctx);
    header('Content-Type: application/json; charset=utf-8');
    echo $resp ?: json_encode(['error' => 'Connection failed']);
    exit;
}

// Validate that the request comes from Telegram via the secret token header
$incomingSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($webhookSecret, $incomingSecret)) {
    http_response_code(403);
    exit;
}

$raw = file_get_contents('php://input');
if (empty($raw)) {
    http_response_code(200);
    exit;
}

$update = json_decode($raw, true);
if (!is_array($update)) {
    http_response_code(200);
    exit;
}

$message = $update['message'] ?? null;
if (!$message) {
    http_response_code(200);
    exit;
}

$chatId    = (string)($message['chat']['id'] ?? '');
$text      = trim($message['text'] ?? '');
$firstName = htmlspecialchars($message['from']['first_name'] ?? '', ENT_QUOTES, 'UTF-8');

if ($chatId === '') {
    http_response_code(200);
    exit;
}

if (str_starts_with($text, '/start') || $text === '/chatid') {
    $reply = "👋 Привет, {$firstName}!\n\n"
           . "Ваш <b>Telegram Chat ID</b>:\n<code>{$chatId}</code>\n\n"
           . "Передайте этот ID администратору — он введёт его в вашем профиле, "
           . "и вы будете получать уведомления о дедлайнах писем.";
    TelegramService::sendMessage($chatId, $reply);
}

http_response_code(200);
echo json_encode(['ok' => true]);
