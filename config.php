<?php
define('APP_ROOT', __DIR__);

if (!defined('JSON_ENCODE_FLAGS')) {
    define('JSON_ENCODE_FLAGS', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
if (!defined('SESSION_IDLE_TIMEOUT_SECONDS')) {
    define('SESSION_IDLE_TIMEOUT_SECONDS', 1800);
}

// Автозагрузка классов
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/ErrorHandler.php';
require_once __DIR__ . '/src/Validator.php';
require_once __DIR__ . '/src/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/src/Middleware/RateLimiter.php';
require_once __DIR__ . '/src/Services/AuditLogger.php';
require_once __DIR__ . '/src/Services/FileCache.php';
require_once __DIR__ . '/src/Services/FileStorage.php';
require_once __DIR__ . '/src/Services/EmailService.php';
require_once __DIR__ . '/src/Services/LetterService.php';
require_once __DIR__ . '/src/Services/RegionService.php';
require_once __DIR__ . '/src/Services/LetterPersistenceService.php';

use App\Logger;
use App\ErrorHandler;
use App\Middleware\RateLimiter;

// Инициализация обработчика ошибок
ErrorHandler::register();
Logger::init();

// База данных вынесена в отдельный файл (удобно для переноса на другой хостинг)
require_once __DIR__ . '/db.php';

// Pusher realtime settings (fill with your credentials)
define('PUSHER_APP_ID', getenv('PUSHER_APP_ID') ?: '');
define('PUSHER_KEY', getenv('PUSHER_KEY') ?: '');
define('PUSHER_SECRET', getenv('PUSHER_SECRET') ?: '');
define('PUSHER_CLUSTER', getenv('PUSHER_CLUSTER') ?: 'eu');

// Настройки для загрузки фото
define('UPLOAD_DIR', 'uploads/photos/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5 МБ
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/jpg']);

if (isApiContext()) {
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
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/**
 * Отправка события в Pusher Channels.
 */
function pusherTrigger(string $channel, string $event, array $payload = []): bool
{
    if (!PUSHER_APP_ID || !PUSHER_KEY || !PUSHER_SECRET || !PUSHER_CLUSTER) {
        return false;
    }

    $dataString = json_encode($payload, JSON_ENCODE_FLAGS);
    if ($dataString === false) {
        $dataString = '{}';
    }

    $body = json_encode([
        'name' => $event,
        'channel' => $channel,
        'data' => $dataString,
    ], JSON_ENCODE_FLAGS);

    $bodyMd5 = md5($body);
    $timestamp = time();

    $queryParams = http_build_query([
        'auth_key' => PUSHER_KEY,
        'auth_timestamp' => $timestamp,
        'auth_version' => '1.0',
        'body_md5' => $bodyMd5,
    ]);

    $path = '/apps/' . PUSHER_APP_ID . '/events';
    $stringToSign = "POST\n{$path}\n{$queryParams}";
    $signature = hash_hmac('sha256', $stringToSign, PUSHER_SECRET);

    $url = 'https://api-' . PUSHER_CLUSTER . '.pusher.com' . $path . '?' . $queryParams . '&auth_signature=' . $signature;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $status >= 400) {
        error_log('Pusher trigger failed: ' . ($error ?: $response));
        return false;
    }

    return true;
}

