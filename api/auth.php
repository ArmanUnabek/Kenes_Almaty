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
use App\Services\SessionManager;
use App\Services\IpAllowlist;

class AuthController extends ApiController
{
    public function handle(): void
    {
        $action = $this->getQueryParam('action') ?? $this->getPostParam('action') ?? 'check';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Mutations require POST
        $mutations = ['login', 'logout', 'switch_region', 'totp_setup', 'totp_enable', 'totp_disable', 'tg_link_code', 'forgot_password', 'reset_password'];
        if (in_array($action, $mutations, true) && $method !== 'POST') {
            $this->error('Метод не поддерживается. Используйте POST.', 405);
        }

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
        CsrfMiddleware::requireVerification();
        $username = trim($_POST['username'] ?? '');
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

        // Жёсткая блокировка аккаунта: 8 последовательных неудачных попыток → 15 минут.
        // Порог поднят с 5 до 8, чтобы затруднить умышленную блокировку чужого аккаунта;
        // основную защиту от перебора даёт мягкая прогрессивная задержка ниже.
        $lockCache  = new FileCache();
        $lockKey    = 'acct_lock_' . md5(strtolower($username));
        $failKey    = 'acct_fail_' . md5(strtolower($username));
        $lockedUntil = $lockCache->get($lockKey);
        if ($lockedUntil && (int)$lockedUntil > time()) {
            $minLeft = (int)ceil(((int)$lockedUntil - time()) / 60);
            try {
                $db->prepare('INSERT INTO login_history (user_id, username, ip_address, user_agent, status, failure_reason) VALUES (NULL, ?, ?, ?, ?, ?)')
                    ->execute([$username, $ip, $_SERVER['HTTP_USER_AGENT'] ?? null, 'blocked', 'account_locked']);
            } catch (\Throwable $e) {}
            $this->json(['error' => "Аккаунт временно заблокирован из-за многократных неудачных попыток входа. Попробуйте через {$minLeft} мин."], 429);
        }

        // Мягкая прогрессивная задержка перед проверкой пароля: со 2-й неудачи
        // 1с, 1.5с, 2с... максимум 3с — замедляет перебор, не блокируя аккаунт.
        $failCount = (int)($lockCache->get($failKey) ?? 0);
        if ($failCount >= 2) {
            usleep(min($failCount * 500, 3000) * 1000);
        }

        $stmt = $db->prepare('SELECT id, username, full_name, role, region_id, password_hash, totp_secret, totp_enabled, updated_at FROM users WHERE username = ? AND is_active = TRUE');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            // Dummy hash check to equalize response time and prevent timing-based user enumeration
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        }
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
            try {
                $db->prepare('INSERT INTO login_history (user_id, username, ip_address, user_agent, status, failure_reason) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$user ? (int)$user['id'] : null, $username, $ip, $_SERVER['HTTP_USER_AGENT'] ?? null, 'failed', 'bad_credentials']);
            } catch (\Throwable $e) {
                error_log('login_history insert failed: ' . $e->getMessage());
            }
            // Track consecutive failures; hard lock after 8 (progressive delay applies from 2)
            $failCount = (int)($lockCache->get($failKey) ?? 0) + 1;
            if ($failCount >= 8) {
                $lockCache->set($lockKey, time() + 900, 900);
                $lockCache->forget($failKey);
            } else {
                $lockCache->set($failKey, $failCount, 600);
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

        // IP allowlist check for admin accounts
        $ipAllowlist = new IpAllowlist($db);
        if ($isAdminRole && !$ipAllowlist->isAllowed((int)$user['id'], $ip)) {
            try {
                SecurityAuditService::log($db, 'IP_BLOCKED_LOGIN', 'security_events', (int)$user['id'],
                    ['ip' => $ip, 'username' => $user['username']], (int)$user['id']);
            } catch (\Throwable $e) {}
            $this->json(['error' => 'Доступ с этого IP-адреса запрещён для вашего аккаунта. Обратитесь к администратору.'], 403);
        }

        // Сброс счётчика блокировки при успешном входе
        $lockCache->forget($lockKey);
        $lockCache->forget($failKey);

        session_regenerate_id(true);

        // Remember me — extend session cookie to 30 days
        $remember = !empty($_POST['remember']);
        if ($remember) {
            $params = session_get_cookie_params();
            setcookie(session_name(), session_id(), time() + 86400 * 30, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            $_SESSION['_remember'] = true;
            // Extend server-side session lifetime to 30 days
            ini_set('session.gc_maxlifetime', 86400 * 30);
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['region_id'] = $user['region_id'];
        $_SESSION['last_activity_at'] = time();
        $_SESSION['_created_at'] = time();
        // Храним отпечаток хэша пароля (не сам bcrypt-хэш) для инвалидации сессии при смене пароля
        $_SESSION['pwd_fingerprint'] = hash('sha256', $user['password_hash']);

        if ($isAdminRole) {
            $defaultRegion = $user['region_id'] ? (int)$user['region_id'] : 1;
            $_SESSION['active_region_id'] = $defaultRegion;
        }

        $now = date('Y-m-d H:i:s');
        $db->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([$now, $user['id']]);

        $freshStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $freshStmt->execute([$user['id']]);
        $_SESSION['pwd_fingerprint'] = hash('sha256', $freshStmt->fetchColumn() ?: $user['password_hash']);

        $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, ip_address) VALUES (?, ?, ?, ?)')
            ->execute([$user['id'], 'login', 'user', $_SERVER['REMOTE_ADDR'] ?? '']);

        // Login history — success
        try {
            $db->prepare('INSERT INTO login_history (user_id, username, ip_address, user_agent, status) VALUES (?, ?, ?, ?, ?)')
                ->execute([(int)$user['id'], $user['username'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? null, 'success']);
        } catch (\Throwable $e) {
            error_log('login_history insert: ' . $e->getMessage());
        }

        // Record active session via SessionManager
        $sessionId = session_id();
        $sessionManager = new SessionManager($db);
        $sessionManager->trackSession((int)$user['id'], $sessionId, $ip, $_SERVER['HTTP_USER_AGENT'] ?? null);

        // Anomaly detection: alert if new IP for this user and send email notification
        $isNewIp = false;
        try {
            $since30d = date('Y-m-d H:i:s', strtotime('-30 days'));
            $knownIps = $db->prepare('SELECT DISTINCT ip_address FROM login_history WHERE user_id = ? AND status = ? AND created_at >= ? LIMIT 20');
            $knownIps->execute([(int)$user['id'], 'success', $since30d]);
            $knownList = array_column($knownIps->fetchAll(), 'ip_address');
            if (!empty($knownList) && !in_array($ip, $knownList, true)) {
                $isNewIp = true;
                SecurityAuditService::log($db, 'NEW_IP_LOGIN', 'security_events', (int)$user['id'],
                    ['ip' => $ip, 'known_ips' => $knownList], null);
            }
        } catch (\Throwable $e) {
            // Non-critical
        }

        // Send email notification for suspicious logins (new IP for admin, or IP not in allowlist)
        if ($isAdminRole && ($isNewIp || $ipAllowlist->needsConfirmation((int)$user['id'], $ip))) {
            try {
                $userEmail = $db->prepare('SELECT email FROM users WHERE id = ?');
                $userEmail->execute([(int)$user['id']]);
                $email = $userEmail->fetchColumn();
                if ($email) {
                    $ua = htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'Неизвестно', ENT_QUOTES, 'UTF-8');
                    $safeIp = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
                    $time = date('d.m.Y H:i:s');
                    EmailService::enqueue($db, $email,
                        '⚠️ Вход в аккаунт с нового IP — Журнал ОС',
                        "<p>В аккаунт <strong>" . htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') . "</strong> выполнен вход с нового IP-адреса.</p>
                         <p><strong>IP:</strong> {$safeIp}<br><strong>Время:</strong> {$time}<br><strong>Браузер:</strong> {$ua}</p>
                         <p>Если это были не вы — немедленно смените пароль и отзовите активные сессии.</p>"
                    );
                }
            } catch (\Throwable $e) {
                error_log('Suspicious login email notification failed: ' . $e->getMessage());
            }
        }

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
            $db->beginTransaction();
            $isMysql = stripos($db->getAttribute(\PDO::ATTR_DRIVER_NAME), 'mysql') !== false;
            $stmt = $db->prepare('SELECT totp_backup_codes FROM users WHERE id = ?' . ($isMysql ? ' FOR UPDATE' : ''));
            $stmt->execute([$userId]);
            $raw = $stmt->fetchColumn();
            if (!$raw) {
                $db->rollBack();
                return false;
            }
            $hashes = json_decode((string)$raw, true);
            if (!is_array($hashes) || !$hashes) {
                $db->rollBack();
                return false;
            }
            $idx = TotpService::matchBackupCode($input, $hashes);
            if ($idx < 0) {
                $db->rollBack();
                return false;
            }
            unset($hashes[$idx]);
            $remaining = array_values($hashes);
            $db->prepare('UPDATE users SET totp_backup_codes = ? WHERE id = ?')
                ->execute([json_encode($remaining, JSON_ENCODE_FLAGS), $userId]);
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('consumeBackupCode failed: ' . $e->getMessage());
            return false;
        }
    }

    private function handleTotpSetup(): void
    {
        $db = $this->db;
        checkAuth();
        CsrfMiddleware::requireVerification();
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
        CsrfMiddleware::requireVerification();
        if (isset($_SESSION['user_id'])) {
            $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, ip_address) VALUES (?, ?, ?, ?)')
                ->execute([$_SESSION['user_id'], 'logout', 'user', $_SERVER['REMOTE_ADDR'] ?? '']);
        }
        // Remove active session record
        try {
            $sid = session_id();
            if ($sid) {
                $db->prepare('DELETE FROM user_sessions WHERE id = ?')->execute([$sid]);
            }
        } catch (\Throwable $e) {}

        $_SESSION = [];
        session_destroy();

        // Delete session cookie on client
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);

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

        $regionId = (int)($_POST['region_id'] ?? 0);
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
     *
     * NOTE: This endpoint uses GET to support redirect-based flows from Telegram.
     * The one-time token (64-char random, single-use, short-lived) provides CSRF
     * protection — an attacker cannot craft a valid link without knowing the token.
     * Ideally this should be POST with CSRF token, but that would break the
     * Telegram bot redirect flow.
     */
    public static function handleTgLogin(\PDO $db): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            echo 'Method not allowed';
            return;
        }

        $token = $_GET['token'] ?? '';
        if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
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

        $stmt2 = $db->prepare('SELECT id, username, full_name, role, region_id, totp_enabled FROM users WHERE id = ? AND is_active = TRUE');
        $stmt2->execute([$row['user_id']]);
        $user = $stmt2->fetch();

        if (!$user) {
            http_response_code(403);
            echo 'Пользователь не найден';
            return;
        }

        // Block Telegram login if 2FA is enabled — user must login with password + TOTP
        if (!empty($user['totp_enabled'])) {
            http_response_code(403);
            echo 'Для пользователя с включённой 2FA вход через Telegram недоступен. Используйте пароль и код аутентификации.';
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
        $_SESSION['_created_at']      = time();

        $db->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $user['id']]);
        $freshStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $freshStmt->execute([$user['id']]);
        $freshHash = $freshStmt->fetchColumn();
        // Отпечаток хэша пароля (не сам bcrypt-хэш) для инвалидации сессии при смене пароля
        $_SESSION['pwd_fingerprint'] = $freshHash ? hash('sha256', (string)$freshHash) : null;
        try {
            $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, ip_address) VALUES (?, ?, ?, ?)')
               ->execute([$user['id'], 'tg_login', 'user', $_SERVER['REMOTE_ADDR'] ?? '']);
        } catch (\Throwable $e) {
        }

        // Register the session in user_sessions — otherwise checkAuth()/validateSession
        // won't find it and will kill the session on the next request.
        try {
            $sessionManager = new SessionManager($db);
            $sessionManager->trackSession((int)$user['id'], session_id(), $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? null);
        } catch (\Throwable $e) {
            error_log('tg_login trackSession failed: ' . $e->getMessage());
        }

        $appUrl = defined('APP_URL') && APP_URL !== '' ? rtrim(APP_URL, '/') : '';
        header('Location: ' . $appUrl . '/api/');
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
        CsrfMiddleware::requireVerification();
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
            $resetUrl = $appUrl . '/login.php?action=reset&token=' . $token;
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
        } else {
            // Constant-time dummy operation to prevent timing-based user enumeration
            try {
                $db->prepare('SELECT 1 FROM password_reset_tokens WHERE 1 = 0')->execute();
            } catch (\Throwable $e) {}
        }

        $this->json(['message' => 'Если указанный email зарегистрирован, вы получите письмо со ссылкой для сброса пароля.']);
    }

    private function handleResetPassword(): void
    {
        $db = $this->db;
        CsrfMiddleware::requireVerification();
        $data  = json_decode(file_get_contents('php://input'), true) ?: [];
        $token = trim($data['token'] ?? '');
        $pass  = $data['password'] ?? '';

        if ($token === '' || strlen($token) !== 64) {
            $this->json(['error' => 'Недействительная ссылка для сброса пароля'], 400);
        }
        if (strlen($pass) < 8) {
            $this->json(['error' => 'Пароль должен содержать минимум 8 символов'], 422);
        }
        $passError = validatePasswordStrength($pass);
        if ($passError) {
            $this->json(['error' => $passError], 422);
        }

        // Clean expired tokens
        try {
            $db->exec("DELETE FROM password_reset_tokens WHERE expires_at < NOW()");
        } catch (\Throwable $e) {
        }

        $stmt = $db->prepare('SELECT * FROM password_reset_tokens WHERE token = ? AND used_at IS NULL AND expires_at > NOW()');
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) {
            $this->json(['error' => 'Ссылка недействительна или уже использована'], 400);
        }

        // Проверка истории паролей (запрет повторного использования последних 5)
        $histStmt = $db->prepare('SELECT password_hash, password_history FROM users WHERE id = ?');
        $histStmt->execute([$row['user_id']]);
        $histData = $histStmt->fetch();
        $history = [];
        $newHistoryJson = null;
        if ($histData) {
            $history = json_decode($histData['password_history'] ?? '[]', true) ?: [];
            foreach (array_filter(array_merge([$histData['password_hash']], $history)) as $oldHash) {
                if ($oldHash && password_verify($pass, (string)$oldHash)) {
                    $this->json(['error' => 'Нельзя использовать один из последних 5 паролей'], 422);
                }
            }
            $newHistory = array_slice(array_filter(array_merge([$histData['password_hash']], $history)), 0, 4);
            $newHistoryJson = json_encode($newHistory, JSON_ENCODE_FLAGS);
        }

        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password_hash = ?, password_history = ?, updated_at = NOW() WHERE id = ?')
           ->execute([$hash, $newHistoryJson, $row['user_id']]);
        $db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')
           ->execute([$row['id']]);

        // Terminate all active sessions of the user after a password reset
        try {
            $sm = new SessionManager($db);
            $sm->terminateAllSessions((int)$row['user_id']);
        } catch (\Throwable $e) {
            error_log('reset_password terminateAllSessions failed: ' . $e->getMessage());
        }

        // Delete all reset tokens for this user
        try {
            $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$row['user_id']]);
        } catch (\Throwable $e) {
        }

        // Email-уведомление о смене пароля
        try {
            $emailStmt = $db->prepare('SELECT email FROM users WHERE id = ?');
            $emailStmt->execute([$row['user_id']]);
            $email = $emailStmt->fetchColumn();
            if ($email) {
                $safeIp   = htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'неизвестно', ENT_QUOTES, 'UTF-8');
                $safeTime = htmlspecialchars(date('d.m.Y H:i:s'), ENT_QUOTES, 'UTF-8');
                EmailService::enqueue($db, $email,
                    'Пароль изменён — Журнал ОС',
                    "<p>Пароль вашей учётной записи был изменён.</p>
                     <p><strong>Время:</strong> {$safeTime}<br><strong>IP:</strong> {$safeIp}</p>
                     <p>Если это были не вы — немедленно обратитесь к администратору.</p>"
                );
            }
        } catch (\Throwable $e) {
            error_log('reset_password email notification failed: ' . $e->getMessage());
        }

        $this->json(['message' => 'Пароль успешно изменён. Теперь вы можете войти.']);
    }

    private function handleCheck(): void
    {
        $db = $this->db;
        if (!isset($_SESSION['user_id'])) {
            $this->json(['authenticated' => false], 401);
        }

        // Full auth validation (terminated sessions, absolute/idle timeouts,
        // password change, IP allowlist). checkAuth() itself responds with 401
        // via denyWithStatus() and exits on failure.
        checkAuth();

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
