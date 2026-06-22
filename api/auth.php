<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/Middleware/CsrfMiddleware.php';

use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimiter;
use App\Services\TotpService;
use App\Services\SecurityAuditService;
use App\Services\EmailService;
use App\Services\FileCache;

$action = $_GET['action'] ?? $_POST['action'] ?? 'check';

// tg_login redirects to the app rather than returning JSON — handle it first
if ($action === 'tg_login') {
    try {
        $db = getDBConnection();
        handleTgLogin($db);
    } catch (\Throwable $e) {
        error_log('tg_login failed: ' . $e->getMessage());
        http_response_code(500);
        echo 'Internal error';
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $db = getDBConnection();

    switch ($action) {
        case 'login':
            handleLogin($db);
            break;

        case 'logout':
            handleLogout($db);
            break;

        case 'csrf':
            handleCsrf($db);
            break;

        case 'switch_region':
            handleSwitchRegion($db);
            break;

        case 'totp_setup':
            handleTotpSetup($db);
            break;

        case 'totp_enable':
            handleTotpEnable($db);
            break;

        case 'totp_disable':
            handleTotpDisable($db);
            break;

        case 'tg_link_code':
            handleTgLinkCode($db);
            break;

        case 'forgot_password':
            handleForgotPassword($db);
            break;

        case 'reset_password':
            handleResetPassword($db);
            break;

        case 'check':
        default:
            handleCheck($db);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log('auth failed: ' . $e->getMessage());
    echo json_encode([
        'error' => 'Внутренняя ошибка сервера'
    ], JSON_ENCODE_FLAGS);
}

function handleLogin($db) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (!RateLimiter::check('login_' . $ip, 10, 900)) {
        http_response_code(429);
        echo json_encode(['error' => 'Слишком много попыток входа. Попробуйте через 15 минут.'], JSON_ENCODE_FLAGS);
        return;
    }

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Логин и пароль обязательны'], JSON_ENCODE_FLAGS);
        return;
    }

    // Доп. лимит против распределённого перебора одного аккаунта (с многих IP).
    // Порог выше IP-лимита, чтобы не давать легко «залочить» легитимного пользователя.
    if (!RateLimiter::check('login_user_' . strtolower($username), 30, 900)) {
        http_response_code(429);
        echo json_encode(['error' => 'Слишком много попыток входа в этот аккаунт. Попробуйте позже.'], JSON_ENCODE_FLAGS);
        return;
    }

    $stmt = $db->prepare('SELECT id, username, full_name, role, region_id, password_hash, totp_secret, totp_enabled FROM users WHERE username = ? AND is_active = TRUE');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        try {
            $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, ip_address) VALUES (NULL, ?, ?, ?)')
                ->execute(['login_failed', 'user:' . $username, $ip]);
        } catch (\Throwable $e) {
            error_log('Failed to log login attempt: ' . $e->getMessage());
        }
        try {
            SecurityAuditService::log($db, 'LOGIN_FAILED', 'security_events', 0,
                ['username' => $username, 'ip' => $ip], null);
        } catch (\Throwable $e) {}
        http_response_code(401);
        echo json_encode(['error' => 'Неверный логин или пароль'], JSON_ENCODE_FLAGS);
        return;
    }

    // 2FA/TOTP check
    $totpEnabled = !empty($user['totp_enabled']) && !empty($user['totp_secret']);
    if ($totpEnabled) {
        $totpCode = $_POST['totp_code'] ?? '';
        if (empty($totpCode)) {
            // Password correct but 2FA code not provided — signal client to show 2FA field
            http_response_code(202);
            echo json_encode([
                'totp_required' => true,
                'message' => 'Введите код двухфакторной аутентификации',
            ], JSON_ENCODE_FLAGS);
            return;
        }
        // Tighter rate limit for the TOTP step: 5 attempts per 5 minutes per IP
        if (!RateLimiter::check('totp_' . $ip, 5, 300)) {
            http_response_code(429);
            echo json_encode(['error' => 'Слишком много попыток. Подождите 5 минут.'], JSON_ENCODE_FLAGS);
            return;
        }
        $matchedCounter = null;
        if (TotpService::verify($user['totp_secret'], $totpCode, $matchedCounter)) {
            // Защита от повтора: один и тот же код (интервал) нельзя использовать дважды.
            $replayKey = 'totp_used_' . (int)$user['id'] . '_' . (int)$matchedCounter;
            $cache = new FileCache();
            if ($cache->get($replayKey)) {
                http_response_code(401);
                echo json_encode(['error' => 'Этот код уже был использован. Дождитесь следующего.'], JSON_ENCODE_FLAGS);
                return;
            }
            // TTL покрывает окно дрейфа (±1 шаг) с запасом.
            $cache->set($replayKey, 1, 120);
        } elseif (!consumeBackupCode($db, (int)$user['id'], $totpCode)) {
            // Ни TOTP, ни резервный код не подошли.
            http_response_code(401);
            echo json_encode(['error' => 'Неверный код двухфакторной аутентификации'], JSON_ENCODE_FLAGS);
            return;
        }
    }

    // Enforce TOTP for admin accounts (opt-in via ENFORCE_ADMIN_2FA, чтобы свежий
    // деплой с дефолтным админом не блокировал первый вход «замкнутым кругом»).
    $isAdminRole = normalizeRole($user['role'] ?? '') === 'admin';
    $enforceAdmin2fa = filter_var(envValue('ENFORCE_ADMIN_2FA', 'false'), FILTER_VALIDATE_BOOLEAN);
    if ($enforceAdmin2fa && $isAdminRole && empty($user['totp_enabled'])) {
        http_response_code(403);
        echo json_encode([
            'error'               => 'Администратор обязан настроить двухфакторную аутентификацию перед входом. Обратитесь к другому администратору.',
            'totp_setup_required' => true,
        ], JSON_ENCODE_FLAGS);
        return;
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['region_id'] = $user['region_id'];
    $_SESSION['last_activity_at'] = time();

    if ($isAdminRole) {
        $defaultRegion = $user['region_id'] ? (int)$user['region_id'] : 1;
        $_SESSION['active_region_id'] = $defaultRegion;
    }

    $now = date('Y-m-d H:i:s');
    $db->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([$now, $user['id']]);

    $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, ip_address) VALUES (?, ?, ?, ?)')
        ->execute([$user['id'], 'login', 'user', $_SERVER['REMOTE_ADDR'] ?? '']);

    unset($user['password_hash'], $user['totp_secret']);
    $user = enrichUserPayload($user);

    echo json_encode([
        'authenticated' => true,
        'user' => $user
    ], JSON_ENCODE_FLAGS);
}

/**
 * Проверяет и «расходует» одноразовый резервный код 2FA. Возвращает true при успехе.
 * Безопасно деградирует, если колонка totp_backup_codes недоступна.
 */
function consumeBackupCode($db, int $userId, string $input): bool
{
    $input = trim($input);
    if ($input === '') {
        return false;
    }
    try {
        $stmt = $db->prepare('SELECT totp_backup_codes FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $raw = $stmt->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
    if (!$raw) {
        return false;
    }
    $hashes = json_decode((string)$raw, true);
    if (!is_array($hashes) || !$hashes) {
        return false;
    }
    $idx = TotpService::matchBackupCode($input, $hashes);
    if ($idx < 0) {
        return false;
    }
    unset($hashes[$idx]);
    $remaining = array_values($hashes);
    try {
        $db->prepare('UPDATE users SET totp_backup_codes = ? WHERE id = ?')
            ->execute([json_encode($remaining, JSON_ENCODE_FLAGS), $userId]);
    } catch (\Throwable $e) {
        error_log('consumeBackupCode update failed: ' . $e->getMessage());
    }
    return true;
}

function handleTotpSetup($db) {
    checkAuth();
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $db->prepare('SELECT username, totp_secret, totp_enabled FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Пользователь не найден'], JSON_ENCODE_FLAGS);
        return;
    }
    // Generate new secret (provisioning — not saved until confirmed)
    $secret = TotpService::generateSecret();
    // Store provisioning secret in session
    $_SESSION['totp_provisioning'] = $secret;
    $uri = TotpService::getUri($secret, $user['username']);
    echo json_encode([
        'secret'     => $secret,
        'uri'        => $uri,
        'qr_url'     => TotpService::getQrUrl($uri),
        'enabled'    => (bool)$user['totp_enabled'],
    ], JSON_ENCODE_FLAGS);
}

function handleTotpEnable($db) {
    checkAuth();
    CsrfMiddleware::requireVerification();
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $code = trim($data['totp_code'] ?? '');
    $secret = $_SESSION['totp_provisioning'] ?? '';
    if (!$secret) {
        http_response_code(400);
        echo json_encode(['error' => 'Сначала получите секрет через totp_setup'], JSON_ENCODE_FLAGS);
        return;
    }
    if (!TotpService::verify($secret, $code)) {
        http_response_code(422);
        echo json_encode(['error' => 'Неверный код. Проверьте время на устройстве.'], JSON_ENCODE_FLAGS);
        return;
    }
    // Генерируем одноразовые резервные коды и сохраняем их хэши.
    $plainCodes = TotpService::generateBackupCodes(10);
    $hashes = TotpService::hashBackupCodes($plainCodes);
    try {
        $db->prepare('UPDATE users SET totp_secret = ?, totp_enabled = TRUE, totp_backup_codes = ? WHERE id = ?')
            ->execute([$secret, json_encode($hashes, JSON_ENCODE_FLAGS), $userId]);
        $backupCodes = $plainCodes;
    } catch (\Throwable $e) {
        // Колонка резервных кодов недоступна — включаем 2FA без них, не ломая поток.
        error_log('totp backup codes unavailable: ' . $e->getMessage());
        $db->prepare('UPDATE users SET totp_secret = ?, totp_enabled = TRUE WHERE id = ?')
            ->execute([$secret, $userId]);
        $backupCodes = [];
    }
    unset($_SESSION['totp_provisioning']);
    echo json_encode([
        'success' => true,
        'message' => '2FA успешно включена',
        'backup_codes' => $backupCodes,
    ], JSON_ENCODE_FLAGS);
}

function handleTotpDisable($db) {
    checkAuth();
    CsrfMiddleware::requireVerification();
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $code = trim($data['totp_code'] ?? '');
    $stmt = $db->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || !$user['totp_enabled'] || !$user['totp_secret']) {
        http_response_code(400);
        echo json_encode(['error' => '2FA не включена'], JSON_ENCODE_FLAGS);
        return;
    }
    if (!TotpService::verify($user['totp_secret'], $code)) {
        http_response_code(422);
        echo json_encode(['error' => 'Неверный код'], JSON_ENCODE_FLAGS);
        return;
    }
    $db->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = FALSE WHERE id = ?')
        ->execute([$userId]);
    echo json_encode(['success' => true, 'message' => '2FA отключена'], JSON_ENCODE_FLAGS);
}

function handleLogout($db) {
    if (isset($_SESSION['user_id'])) {
        $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, ip_address) VALUES (?, ?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'logout', 'user', $_SERVER['REMOTE_ADDR'] ?? '']);
    }

    $_SESSION = [];
    session_destroy();

    echo json_encode([
        'authenticated' => false,
        'message' => 'Вы вышли из системы'
    ], JSON_ENCODE_FLAGS);
}

function handleCsrf($db) {
    echo json_encode([
        'csrf_token' => CsrfMiddleware::getToken()
    ], JSON_ENCODE_FLAGS);
}

function handleSwitchRegion($db) {
    checkAuth();
    CsrfMiddleware::requireVerification();
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Только супер-админ может переключать регион'], JSON_ENCODE_FLAGS);
        return;
    }

    $regionId = (int)($_POST['region_id'] ?? $_GET['region_id'] ?? 0);
    if ($regionId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'region_id обязателен'], JSON_ENCODE_FLAGS);
        return;
    }

    $stmt = $db->prepare('SELECT id FROM regions WHERE id = ? AND is_active = TRUE');
    $stmt->execute([$regionId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Регион не найден или неактивен'], JSON_ENCODE_FLAGS);
        return;
    }

    setActiveRegionId($regionId);
    $user = getCurrentUser();
    if ($user) {
        $user = enrichUserPayload($user);
    }

    echo json_encode([
        'success' => true,
        'active_region_id' => $regionId,
        'user' => $user,
        'message' => 'Регион переключён'
    ], JSON_ENCODE_FLAGS);
}

function handleTgLogin(\PDO $db): void
{
    $token = $_GET['token'] ?? '';
    if ($token === '' || strlen($token) !== 64) {
        http_response_code(400);
        echo 'Invalid token';
        return;
    }

    // Delete stale expired tokens while we're here
    try {
        $db->exec("DELETE FROM telegram_login_tokens WHERE expires_at < NOW()");
    } catch (\Throwable $e) {}

    $stmt = $db->prepare('SELECT * FROM telegram_login_tokens WHERE token = ? AND expires_at > NOW() AND used_at IS NULL');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(403);
        echo 'Ссылка недействительна или уже была использована';
        return;
    }

    $stmt2 = $db->prepare('SELECT id, username, full_name, role, region_id FROM users WHERE id = ? AND is_active = TRUE');
    $stmt2->execute([$row['user_id']]);
    $user = $stmt2->fetch();

    if (!$user) {
        http_response_code(403);
        echo 'Пользователь не найден';
        return;
    }

    // Mark token as used
    $db->prepare('UPDATE telegram_login_tokens SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);

    // Start session
    if (function_exists('configureSessionCookie')) {
        configureSessionCookie();
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    session_regenerate_id(true);

    $_SESSION['user_id']          = $user['id'];
    $_SESSION['username']         = $user['username'];
    $_SESSION['role']             = $user['role'];
    $_SESSION['region_id']        = $user['region_id'];
    $_SESSION['last_activity_at'] = time();

    $db->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $user['id']]);
    try {
        $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, ip_address) VALUES (?, ?, ?, ?)')
           ->execute([$user['id'], 'tg_login', 'user', $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Throwable $e) {}

    header('Location: /api/');
}

function handleTgLinkCode(\PDO $db): void
{
    checkAuth();
    CsrfMiddleware::requireVerification();

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Требуется авторизация'], JSON_ENCODE_FLAGS);
        return;
    }

    // Delete old unused codes for this user
    try {
        $db->prepare('DELETE FROM telegram_link_codes WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
    } catch (\Throwable $e) {}

    $code      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

    $db->prepare('INSERT INTO telegram_link_codes (user_id, code, expires_at) VALUES (?, ?, ?)')
       ->execute([$userId, $code, $expiresAt]);

    echo json_encode([
        'code'       => $code,
        'expires_in' => 600,
        'bot'        => defined('TELEGRAM_BOT_USERNAME') ? TELEGRAM_BOT_USERNAME : '',
    ], JSON_ENCODE_FLAGS);
}

function handleForgotPassword(\PDO $db): void
{
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = trim($data['email'] ?? $_POST['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Укажите корректный email'], JSON_ENCODE_FLAGS);
        return;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!RateLimiter::check('forgot_' . $ip, 5, 900)) {
        http_response_code(429);
        echo json_encode(['error' => 'Слишком много попыток. Подождите 15 минут.'], JSON_ENCODE_FLAGS);
        return;
    }

    $stmt = $db->prepare('SELECT id, full_name, username FROM users WHERE email = ? AND is_active = TRUE');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Always respond OK to avoid user enumeration
    if ($user) {
        // Invalidate old tokens
        $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$user['id']]);

        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour
        $db->prepare('INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)')
           ->execute([$user['id'], $token, $expiresAt]);

        $appUrl   = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        $resetUrl = $appUrl . '/login.html?action=reset&token=' . $token;
        $name     = htmlspecialchars($user['full_name'] ?? $user['username'], ENT_QUOTES, 'UTF-8');

        $bodyHtml = "
            <p>Здравствуйте, <strong>{$name}</strong>!</p>
            <p>Вы запросили сброс пароля для учётной записи <em>{$user['username']}</em>.</p>
            <p><a href=\"{$resetUrl}\">Нажмите здесь для сброса пароля</a></p>
            <p>Ссылка действительна <strong>1 час</strong>. Если вы не запрашивали сброс — проигнорируйте это письмо.</p>
        ";

        try {
            EmailService::enqueue($db, $email, 'Сброс пароля — Журнал ОС', $bodyHtml);
        } catch (\Throwable $e) {
            error_log('handleForgotPassword: email enqueue failed: ' . $e->getMessage());
        }
    }

    echo json_encode(['message' => 'Если указанный email зарегистрирован, вы получите письмо со ссылкой для сброса пароля.'], JSON_ENCODE_FLAGS);
}

function handleResetPassword(\PDO $db): void
{
    $data  = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = trim($data['token'] ?? '');
    $pass  = $data['password'] ?? '';

    if ($token === '' || strlen($token) !== 64) {
        http_response_code(400);
        echo json_encode(['error' => 'Недействительная ссылка для сброса пароля'], JSON_ENCODE_FLAGS);
        return;
    }
    if (strlen($pass) < 8) {
        http_response_code(422);
        echo json_encode(['error' => 'Пароль должен содержать минимум 8 символов'], JSON_ENCODE_FLAGS);
        return;
    }

    // Clean expired tokens
    try { $db->exec("DELETE FROM password_reset_tokens WHERE expires_at < NOW()"); } catch (\Throwable $e) {}

    $stmt = $db->prepare('SELECT * FROM password_reset_tokens WHERE token = ? AND used_at IS NULL');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(400);
        echo json_encode(['error' => 'Ссылка недействительна или уже использована'], JSON_ENCODE_FLAGS);
        return;
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $db->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
       ->execute([$hash, $row['user_id']]);
    $db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')
       ->execute([$row['id']]);

    // Invalidate all sessions for this user
    try {
        $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$row['user_id']]);
    } catch (\Throwable $e) {}

    echo json_encode(['message' => 'Пароль успешно изменён. Теперь вы можете войти.'], JSON_ENCODE_FLAGS);
}

function handleCheck($db) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['authenticated' => false], JSON_ENCODE_FLAGS);
        return;
    }

    $lastActivity = (int)($_SESSION['last_activity_at'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > SESSION_IDLE_TIMEOUT_SECONDS) {
        $_SESSION = [];
        session_destroy();
        http_response_code(401);
        echo json_encode([
            'authenticated' => false,
            'message' => 'Сессия истекла по неактивности'
        ], JSON_ENCODE_FLAGS);
        return;
    }

    $stmt = $db->prepare('SELECT id, username, full_name, role, region_id, email, totp_enabled FROM users WHERE id = ? AND is_active = TRUE');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION = [];
        session_destroy();
        http_response_code(401);
        echo json_encode(['authenticated' => false], JSON_ENCODE_FLAGS);
        return;
    }

    $_SESSION['last_activity_at'] = time();
    $user = enrichUserPayload($user);

    $payload = [
        'authenticated' => true,
        'user' => $user,
    ];

    if ($user['is_admin']) {
        $regions = $db->query('SELECT id, name_kz, name_ru, code, is_active FROM regions ORDER BY name_ru')->fetchAll();
        $payload['regions'] = $regions;
    }

    echo json_encode($payload, JSON_ENCODE_FLAGS);
}
