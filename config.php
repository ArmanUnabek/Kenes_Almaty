<?php
define('APP_ROOT', __DIR__);

require_once __DIR__ . '/vendor/autoload.php';

if (!defined('JSON_ENCODE_FLAGS')) {
    // Defense in depth: HEX_TAG/AMP/APOS/QUOT protect against the JSON output
    // ever being embedded into HTML (e.g. inline <script> data) without a
    // dedicated escaper.
    define('JSON_ENCODE_FLAGS', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

use App\Logger;
use App\ErrorHandler;
use App\Middleware\RateLimiter;

// Инициализация обработчика ошибок
ErrorHandler::register();
Logger::init();

// База данных вынесена в отдельный файл (удобно для переноса на другой хостинг)
require_once __DIR__ . '/db.php';

if (!defined('SESSION_IDLE_TIMEOUT_SECONDS')) {
    $sessionLifetime = (int) (envValue('SESSION_LIFETIME', '7200') ?: 7200);
    define('SESSION_IDLE_TIMEOUT_SECONDS', max(300, $sessionLifetime));
}

// Timezone must be set after db.php loads the .env file so APP_TIMEZONE is available
date_default_timezone_set(envValue('APP_TIMEZONE') ?? 'Asia/Almaty');

// SMTP email settings (optional)
define('SMTP_HOST',      getenv('SMTP_HOST')      ?: '');
define('SMTP_PORT',      (int)(getenv('SMTP_PORT')  ?: 587));
define('SMTP_USER',      getenv('SMTP_USER')      ?: '');
define('SMTP_PASS',      getenv('SMTP_PASS')      ?: '');
define('SMTP_FROM',      getenv('SMTP_FROM')      ?: 'noreply@example.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Журнал ОС');

// URL приложения (используется для генерации ссылок в письмах/боте)
define('APP_URL', rtrim(envValue('APP_URL') ?? '', '/'));

// SMS (Mobizon)
define('SMS_ENABLED',     filter_var(envValue('SMS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN));
define('SMS_PROVIDER',    envValue('SMS_PROVIDER', 'mobizon'));
define('MOBIZON_API_KEY', envValue('MOBIZON_API_KEY', ''));
define('MOBIZON_SENDER',  envValue('MOBIZON_SENDER', 'InfoSMS'));

// Web Push (VAPID)
define('PUSH_ENABLED',        filter_var(envValue('PUSH_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN));
define('VAPID_PUBLIC_KEY',    envValue('VAPID_PUBLIC_KEY', ''));
define('VAPID_PRIVATE_KEY',   envValue('VAPID_PRIVATE_KEY', ''));
define('VAPID_SUBJECT',       envValue('VAPID_SUBJECT', 'mailto:admin@example.com'));

// Redis
define('REDIS_HOST',     envValue('REDIS_HOST', ''));
define('REDIS_PORT',     (int)(envValue('REDIS_PORT', '6379') ?: 6379));
define('REDIS_PASSWORD', envValue('REDIS_PASSWORD', ''));

// Webhooks
define('WEBHOOK_SECRET_KEY',  envValue('WEBHOOK_SECRET_KEY', ''));
define('API_KEYS_ENABLED',    filter_var(envValue('API_KEYS_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN));

// Telegram Bot (опционально — для уведомлений и webhook-команд)
define('TELEGRAM_BOT_TOKEN',      envValue('TELEGRAM_BOT_TOKEN')      ?? '');
define('TELEGRAM_BOT_USERNAME',   envValue('TELEGRAM_BOT_USERNAME')   ?? '');
define('TELEGRAM_WEBHOOK_SECRET', envValue('TELEGRAM_WEBHOOK_SECRET') ?? '');

// WhatsApp Cloud API (Meta Business, optional)
define('WHATSAPP_TOKEN',   envValue('WHATSAPP_TOKEN')   ?? '');
define('WHATSAPP_PHONE_ID', envValue('WHATSAPP_PHONE_ID') ?? '');

// Настройки для загрузки фото
define('UPLOAD_DIR', APP_ROOT . '/uploads/photos/');
$uploadMaxMb = (int) (envValue('UPLOAD_MAX_MB', '10') ?: 10);
define('MAX_FILE_SIZE', max(1, $uploadMaxMb) * 1024 * 1024);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/jpg']);

/**
 * Эндпоинты, отдающие бинарные файлы (не JSON).
 */
function isBinaryApiEndpoint(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $base = basename(str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? ''));
    $cache = in_array($base, ['member_photo.php', 'scan_download.php', 'qr.php'], true);
    return $cache;
}

if (isApiContext() && !isBinaryApiEndpoint()) {
    // Rate limiting
    // Более мягкий лимит для SPA: множество фоновых API-запросов
    // с одного IP не должны блокировать вход в систему.
    RateLimiter::requireCheck(1000, 3600);
    
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOriginsRaw = getenv('ALLOWED_ORIGINS') ?: '';
        if ($origin && $allowedOriginsRaw) {
            $allowedOrigins = array_filter(array_map('trim', explode(',', $allowedOriginsRaw)));
            if (in_array($origin, $allowedOrigins, true)) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
            }
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

