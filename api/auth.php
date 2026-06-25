<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../src/ApiController.php';

use App\ApiController;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimiter;
use App\Services\TotpService;
use App\Services\SecurityAuditService;
use App\Services\EmailService;
use App\Services\FileCache;

class AuthController extends ApiController
{
    public function handle(): void
    {
        $action = $this->getQueryParam('action') ?? $this->getPostParam('action') ?? 'check';

        try {
            switch ($action) {
                case 'login':
                    $this->handleLogin();
                    break;
                case 'logout':
                    $this->handleLogout();
                    break;
                case 'csrf':
                    $this->handleCsrf();
                    break;
                case 'switch_region':
                    $this->handleSwitchRegion();
                    break;
                case 'totp_setup':
                    $this->handleTotpSetup();
                    break;
                case 'totp_enable':
                    $this->handleTotpEnable();
                    break;
                case 'totp_disable':
                    $this->handleTotpDisable();
                    break;
                case 'tg_link_code':
                    $this->handleTgLinkCode();
                    break;
                case 'forgot_password':
                    $this->handleForgotPassword();
                    break;
                case 'reset_password':
                    $this->handleResetPassword();
                    break;
                case 'check':
                default:
                    $this->handleCheck();
                    break;
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('auth failed: ' . $e->getMessage());
            echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_ENCODE_FLAGS);
        }
    }

    private function handleLogin(): void
    {
        $db = $this->db;
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if (!RateLimiter::check('login_' . $ip, 10, 900)) {
            $this->json(['error' => 'Слишком много попыток входа. Попробуйте через 15 минут.'], 429);
        }

        if (empty($username) || empty($password)) {
            $this->json(['error' => 'Логин и пароль обязательны'], 400);
        }

        // Доп. лимит против распределённого перебора одного аккаунта (с многих IP).
        // Порог выше IP-лимита, чтобы не давать легко «залочить» легитимного пользователя.
        if (!RateLimiter::check('login_user_' . strtolower($username), 30, 900)) {
            $this->json(['error' => 'Слишком много попыток входа в этот аккаунт. Попробуйте позже.'], 429);
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
            } catch (\Throwable $e) {
            }
            $this->json(['error' => 'Неверный логин или пароль'], 401);
        }

        // 2FA/TOTP check
        $totpEnabled = !empty($user['totp_enabled']) && !empty($user['totp_secret']);
        if ($totpEnabled) {
            $totpCode = $_POST['totp_code'] ?? '';
            if (empty($totpCode)) {
                // Password correct but 2FA code not provided — signal client to show 2FA field
                $this->json([
                    'totp_required' => true,
                    'message' => 'Введите код двухфакторной аутентификации',
                ], 202);
            }
            // Tighter rate limit for the TOTP step: 5 attempts per 5 minutes per IP
            if (!RateLimiter::check('totp_' . $ip, 5, 300)) {
                $this->json(['error' => 'Слишком много попыток. Подождите 5 минут.'], 429);
            }
            $matchedCounter = null;
            if (TotpService::verify($user['totp_secret'], $totpCode, $matchedCounter)) {
                // Защита от повтора: один и тот же код (интервал) нельзя использовать дважды.
                $replayKey = 'totp_used_' . (int)$user['id'] . '_' . (int)$matchedCounter;
                $cache = new FileCache();
                if ($cache->get($replayKey)) {
                    $this->json(['error' => 'Этот код уже был использован. Дождитесь следующего.'], 401);
                }
                // TTL покрывает окно дрейфа (±1 шаг) с запасом.
                $cache->set($replayKey, 1, 120);
            } elseif (!$this->consumeBackupCode((int)$user['id'], $totpCode)) {
                // Ни TOTP, ни резервный код не подошли.
                $this->json(['error' => 'Неверный код двухфакторной аутентификации'], 401);
            }
        }

        // Enforce TOTP for admin accounts (opt-in via ENFORCE_ADMIN_2FA, чтобы свежий
        // деплой с дефолтным админом не блокировал первый вход «замкнутым кругом»).
        $isAdminRole = normalizeRole($user['role'] ?? '') === 'admin';
        $enforceAdmin2fa = filter_var(envValue('ENFORCE_ADMIN_2FA', 'false'), FILTER_VALIDATE_BOOLEAN);
        if ($enforceAdmin2fa && $isAdminRole && empty($user['totp_enabled'])) {
            $this->json([
                'error'               => 'Администратор обязан настроить двухфакторную аутентификацию перед входом. Обратитесь к другому администратору.',
                'totp_setup_required' => true,
            ], 403);
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

        $this->json([
            'authenticated' => true,
            'user' => $user,
        ]);
    }

    /**
     * Проверяет и «расходует» одноразовый резервный код 2FA. Возвращает true при успехе.
     * Безопасно деградирует, если колонка totp_backup_codes недоступна.
     */
    private function consumeBackupCode(int $userId, string $input): bool
    {
        $db = $this->db;
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

    private function handleTotpSetup(): void
    {
        $db = $this->db;
        checkAuth();
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $db->prepare('SELECT username, totp_secret, totp_enabled FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            $this->json(['error' => 'Пользователь не найден'], 404);
        }
        // Generate new secret (provisioning — not saved until confirmed)
        $secret = TotpService::generateSecret();
        // Store provisioning secret in session
        $_SESSION['totp_provisioning'] = $secret;
        $uri = TotpService::getUri($secret, $user['username']);
        $this->json([
            'secret'  => $secret,
            'uri'     => $uri,
            'qr_url'  => TotpService::getQrUrl($uri),
            'enabled' => (bool)$user['totp_enabled'],
        ]);
    }

    private function handleTotpEnable(): void
    {
        $db = $this->db;
        checkAuth();
        CsrfMiddleware::requireVerification();
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $code = trim($data['totp_code'] ?? '');
        $secret = $_SESSION['totp_provisioning'] ?? '';
        if (!$secret) {
            $this->json(['error' => 'Сначала получите секрет через totp_setup'], 400);
        }
        if (!TotpService::verify($secret, $code)) {
            $this->json(['error' => 'Неверный код. Проверьте время на устройстве.'], 422);
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
        $this->json([
            'success' => true,
            'message' => '2FA успешно включена',
            'backup_codes' => $backupCodes,
        ]);
    }

    private function handleTotpDisable(): void
    {
        $db = $this->db;
        checkAuth();
        CsrfMiddleware::requireVerification();
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $code = trim($data['totp_code'] ?? '');
        $stmt = $db->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || !$user['totp_enabled'] || !$user['totp_secret']) {
            $this->json(['error' => '2FA не включена'], 400);
        }
        if (!TotpService::verify($user['totp_secret'], $code)) {
            $this->json(['error' => 'Неверный код'], 422);
        }
        $db->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = FALSE WHERE id = ?')
            ->execute([$userId]);
        $this->json(['success' => true, 'message' => '2FA отключена']);
    }

    private function handleLogout(): void
    {
        $db = $this->db;
        if (isset($_SESSION['user_id'])) {
            $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, ip_address) VALUES (?, ?, ?, ?)')
                ->execute([$_SESSION['user_id'], 'logout', 'user', $_SERVER['REMOTE_ADDR'] ?? '']);
        }

        $_SESSION = [];
        session_destroy();

        $this->json([
            'authenticated' => false,
            'message' => 'Вы вышли из системы',
        ]);
    }

    private function handleCsrf(): void
    {
        $this->json(['csrf_token' => CsrfMiddleware::getToken()]);
    }

    private function handleSwitchRegion(): void
    {
        $db = $this->db;
        checkAuth();
        CsrfMiddleware::requireVerification();
        if (!isAdmin()) {
            $this->json(['error' => 'Только супер-админ может переключать регион'], 403);
        }

        $regionId = (int)($_POST['region_id'] ?? $_GET['region_id'] ?? 0);
        if ($regionId <= 0) {
            $this->json(['error' => 'region_id обязателен'], 400);
        }

        $stmt = $db->prepare('SELECT id FROM regions WHERE id = ? AND is_active = TRUE');
        $stmt->execute([$regionId]);
        if (!$stmt->fetch()) {
            $this->json(['error' => 'Регион не найден или неактивен'], 404);
        }

        setActiveRegionId($regionId);
        $user = getCurrentUser();
        if ($user) {
            $user = enrichUserPayload($user);
        }

        $this->json([
            'success' => true,
            'active_region_id' => $regionId,
            'user' => $user,
            'message' => 'Регион переключён',
        ]);
    }

    /**
     * Telegram one-tap login: redirects to the app instead of returning JSON, so
     * it is handled separately (before the controller sets the JSON content-type).
     */
    public static function handleTgLogin(\PDO $db): void
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
        } catch (\Throwable $e) {
        }

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
        } catch (\Throwable $e) {
        }

        header('Location: /api/');
    }

    private function handleTgLinkCode(): void
    {
        $db = $this->db;
        checkAuth();
        CsrfMiddleware::requireVerification();

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            $this->json(['error' => 'Требуется авторизация'], 401);
        }

        // Delete old unused codes for this user
        try {
            $db->prepare('DELETE FROM telegram_link_codes WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        } catch (\Throwable $e) {
        }

        $code      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

        $db->prepare('INSERT INTO telegram_link_codes (user_id, code, expires_at) VALUES (?, ?, ?)')
           ->execute([$userId, $code, $expiresAt]);

        $this->json([
            'code'       => $code,
            'expires_in' => 600,
            'bot'        => defined('TELEGRAM_BOT_USERNAME') ? TELEGRAM_BOT_USERNAME : '',
        ]);
    }

    private function handleForgotPassword(): void
    {
        $db = $this->db;
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $email = trim($data['email'] ?? $_POST['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json(['error' => 'Укажите корректный email'], 400);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!RateLimiter::check('forgot_' . $ip, 5, 900)) {
            $this->json(['error' => 'Слишком много попыток. Подождите 15 минут.'], 429);
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

        $this->json(['message' => 'Если указанный email зарегистрирован, вы получите письмо со ссылкой для сброса пароля.']);
    }

    private function handleResetPassword(): void
    {
        $db = $this->db;
        $data  = json_decode(file_get_contents('php://input'), true) ?: [];
        $token = trim($data['token'] ?? '');
        $pass  = $data['password'] ?? '';

        if ($token === '' || strlen($token) !== 64) {
            $this->json(['error' => 'Недействительная ссылка для сброса пароля'], 400);
        }
        if (strlen($pass) < 8) {
            $this->json(['error' => 'Пароль должен содержать минимум 8 символов'], 422);
        }

        // Clean expired tokens
        try {
            $db->exec("DELETE FROM password_reset_tokens WHERE expires_at < NOW()");
        } catch (\Throwable $e) {
        }

        $stmt = $db->prepare('SELECT * FROM password_reset_tokens WHERE token = ? AND used_at IS NULL');
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) {
            $this->json(['error' => 'Ссылка недействительна или уже использована'], 400);
        }

        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
           ->execute([$hash, $row['user_id']]);
        $db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')
           ->execute([$row['id']]);

        // Invalidate all sessions for this user
        try {
            $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$row['user_id']]);
        } catch (\Throwable $e) {
        }

        $this->json(['message' => 'Пароль успешно изменён. Теперь вы можете войти.']);
    }

    private function handleCheck(): void
    {
        $db = $this->db;
        if (!isset($_SESSION['user_id'])) {
            $this->json(['authenticated' => false], 401);
        }

        $lastActivity = (int)($_SESSION['last_activity_at'] ?? 0);
        if ($lastActivity > 0 && (time() - $lastActivity) > SESSION_IDLE_TIMEOUT_SECONDS) {
            $_SESSION = [];
            session_destroy();
            $this->json([
                'authenticated' => false,
                'message' => 'Сессия истекла по неактивности',
            ], 401);
        }

        $stmt = $db->prepare('SELECT id, username, full_name, role, region_id, email, totp_enabled FROM users WHERE id = ? AND is_active = TRUE');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            $_SESSION = [];
            session_destroy();
            $this->json(['authenticated' => false], 401);
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

        $this->json($payload);
    }
}

// tg_login redirects to the app rather than returning JSON — handle it first,
// before AuthController's constructor sets the JSON content-type header.
$action = $_GET['action'] ?? $_POST['action'] ?? 'check';
if ($action === 'tg_login') {
    try {
        $db = getDBConnection();
        AuthController::handleTgLogin($db);
    } catch (\Throwable $e) {
        error_log('tg_login failed: ' . $e->getMessage());
        http_response_code(500);
        echo 'Internal error';
    }
    exit;
}

$controller = new AuthController();
$controller->handle();
