<?php
require_once __DIR__ . '/config.php';

if (function_exists('configureSessionCookie')) {
    configureSessionCookie();
}
if (session_status() === PHP_SESSION_NONE) {
    $db = getDBConnection();
    session_set_save_handler(new \App\Services\PdoSessionHandler($db), true);
    session_start();
}

// Refresh session cookie for "remember me" sessions (extend 30-day expiry)
if (!empty($_SESSION['_remember']) && !headers_sent()) {
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), time() + 86400 * 30, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

// Security headers for all authenticated responses
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests;");
    // HSTS — enforce HTTPS for 1 year
    if ((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

if (!isset($db)) {
    $db = getDBConnection();
}

function denyWithStatus(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_ENCODE_FLAGS);
    exit;
}

function normalizeRole(?string $role): string
{
    return \App\Auth\AccessPolicy::normalizeRole($role);
}

function checkIpAllowlist(): void
{
    // Global admin IP allowlist from ADMIN_ALLOWED_IPS env var
    $allowedEnv = envValue('ADMIN_ALLOWED_IPS', '');
    if ($allowedEnv !== '' && $allowedEnv !== null) {
        $role = normalizeRole($_SESSION['role'] ?? 'viewer');
        if (\App\Auth\AccessPolicy::isAdmin($role)) {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $allowed  = array_filter(array_map('trim', explode(',', $allowedEnv)));
            if (!empty($allowed) && !in_array($clientIp, $allowed, true)) {
                denyWithStatus(403, 'Доступ с этого IP запрещён для администратора');
            }
        }
    }
}

function checkAuth(): void
{
    if (!isset($_SESSION['user_id'])) {
        denyWithStatus(401, 'Требуется авторизация');
    }

    checkIpAllowlist();

    // Validate session is not terminated and update last_active
    $sid = session_id();
    if ($sid) {
        try {
            $db = getDBConnection();
            $sessionManager = new \App\Services\SessionManager($db);
            if (!$sessionManager->validateSession($sid)) {
                // Grace window right after login: SessionManager::trackSession() (called
                // synchronously from handleLogin()) and PdoSessionHandler::write() (this same
                // login request's shutdown handler) both write user_sessions for this id, and
                // a concurrent request fired immediately after the browser gets the login
                // response can still land between the two (or hit a transient blip) and see
                // validateSession() return false even though the session is genuinely fresh
                // and was never actually terminated. Hard-destroying on a single false here
                // is what caused the immediate post-login logout: the DELETE + cleared cookie
                // killed the session for every other in-flight/future request too. So within a
                // short window after login we self-heal (re-assert the row) instead of
                // destroying — a real termination (logout, admin revoke, idle/absolute
                // timeout) will still be caught on the very next request once the window
                // passes, so nothing here weakens actual revocation.
                $createdAt = (int)($_SESSION['_created_at'] ?? 0);
                $withinLoginGrace = $createdAt > 0 && (time() - $createdAt) <= 10;

                if ($withinLoginGrace) {
                    $sessionManager->trackSession(
                        (int)$_SESSION['user_id'],
                        $sid,
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? null
                    );
                } else {
                    $_SESSION = [];
                    session_destroy();
                    denyWithStatus(401, 'Сессия была завершена');
                }
            } else {
                $sessionManager->updateLastActive($sid);
            }
        } catch (\Throwable $e) {
            // Non-critical — don't block auth if session tracking fails
        }
    }

    $lastActivity = (int)($_SESSION['last_activity_at'] ?? 0);
    $isRemembered = !empty($_SESSION['_remember']);
    $idleTimeout = $isRemembered ? 86400 * 30 : SESSION_IDLE_TIMEOUT_SECONDS;
    if ($lastActivity > 0 && (time() - $lastActivity) > $idleTimeout) {
        $_SESSION = [];
        session_destroy();
        denyWithStatus(401, 'Сессия истекла по неактивности');
    }

    // Absolute session timeout: 8 hours for normal sessions, 30 days for "remember me"
    $createdAt = (int)($_SESSION['_created_at'] ?? 0);
    $absoluteTimeout = !empty($_SESSION['_remember']) ? 86400 * 30 : 28800;
    if ($createdAt > 0 && (time() - $createdAt) > $absoluteTimeout) {
        $_SESSION = [];
        session_destroy();
        denyWithStatus(401, 'Сессия истекла. Пожалуйста, войдите снова.');
    }

    // Invalidate session if password was changed after login.
    // В сессии хранится sha256-отпечаток bcrypt-хэша (не сам хэш) — сравниваем с отпечатком свежего хэша из БД.
    $sessionPwdFingerprint = $_SESSION['pwd_fingerprint'] ?? null;
    if ($sessionPwdFingerprint !== null) {
        global $db;
        if (!isset($db)) {
            $db = getDBConnection();
        }
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if (!$row || !hash_equals($sessionPwdFingerprint, hash('sha256', (string)$row['password_hash']))) {
            $_SESSION = [];
            session_destroy();
            denyWithStatus(401, 'Пароль был изменён. Пожалуйста, войдите снова.');
        }
    }

    $_SESSION['last_activity_at'] = time();
}

function tryApiKeyAuth(): bool
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        return false;
    }

    $rawKey = $m[1];
    if (!str_starts_with($rawKey, 'ak_')) {
        return false;
    }

    global $db;
    if (!isset($db)) {
        $db = getDBConnection();
    }

    require_once __DIR__ . '/src/Services/ApiKeyService.php';
    $user = \App\Services\ApiKeyService::validateKey($db, $rawKey);
    if (!$user) {
        denyWithStatus(401, 'Недействительный API-ключ');
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['api_key_auth'] = true;
    $_SESSION['last_activity_at'] = time();
    $_SESSION['_created_at'] = time();
    unset($_SESSION['_remember']);

    try {
        $sm = new \App\Services\SessionManager($db);
        $sm->trackSession((int)$user['id'], session_id(), $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? null);
    } catch (\Throwable $e) {}

    return true;
}

function getCurrentUser(): ?array
{
    global $db;
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $db->prepare('SELECT id, username, full_name, role, region_id, member_id, email, is_active, last_login, created_at, updated_at FROM users WHERE id = ? AND is_active = TRUE');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    $user['role'] = normalizeRole($user['role'] ?? 'viewer');
    return $user;
}

function requireRole(array $allowedRoles): void
{
    checkAuth();
    $user = getCurrentUser();
    if (!$user) {
        denyWithStatus(401, 'Пользователь не найден');
    }
    if (!\App\Auth\AccessPolicy::hasAnyRole($user['role'] ?? 'viewer', $allowedRoles)) {
        denyWithStatus(403, 'Недостаточно прав');
    }
}

function requireWriteAccess(): void
{
    requireRole(['admin', 'moderator']);
}

function requireDeleteAccess(): void
{
    requireRole(['admin']);
}

function isAdmin(): bool
{
    $user = getCurrentUser();
    return (bool)$user && \App\Auth\AccessPolicy::isAdmin($user['role'] ?? '');
}

function isManager(): bool
{
    // Backward compatibility with old function name.
    $user = getCurrentUser();
    return (bool)$user && \App\Auth\AccessPolicy::canWrite($user['role'] ?? '');
}

function canWrite(): bool
{
    return isManager();
}

function canDelete(): bool
{
    return isAdmin();
}

function canExport(): bool
{
    return isAdmin();
}

function requireExportAccess(): void
{
    requireRole(['admin']);
}

/**
 * Определяет region_id для создания записи с проверкой прав.
 * Модератор/viewer не может писать в чужой регион.
 */
function resolveRegionIdForWrite(?int $requestedRegionId = null): int
{
    $user = getCurrentUser();
    if (!$user) {
        denyWithStatus(401, 'Требуется авторизация');
    }

    try {
        return \App\Auth\AccessPolicy::resolveRegionIdForWrite($user, $requestedRegionId, getCurrentRegionId());
    } catch (\App\Auth\AccessDenied $e) {
        denyWithStatus($e->getStatus(), $e->getMessage());
    }
}

function assertEventRegionAccess(?array $event): void
{
    if (!$event) {
        return;
    }
    $regionId = (int)($event['region_id'] ?? 0);
    if ($regionId > 0 && !canAccessRegion($regionId)) {
        denyWithStatus(403, 'Доступ к мероприятию запрещён');
    }
}

function getCurrentRegionId(): ?int
{
    $user = getCurrentUser();
    if (!$user) {
        return null;
    }

    $activeRegionId = (isset($_SESSION['active_region_id']) && $_SESSION['active_region_id'] !== '' && $_SESSION['active_region_id'] !== null)
        ? (int)$_SESSION['active_region_id']
        : null;

    return \App\Auth\AccessPolicy::currentRegionId($user, $activeRegionId);
}

/**
 * Регион для чтения списков: admin может видеть все регионы (null),
 * moderator/viewer — только свой назначенный region_id.
 */
function resolveRegionIdForRead(): ?int
{
    $user = getCurrentUser();
    if (!$user) {
        denyWithStatus(401, 'Требуется авторизация');
    }
    try {
        return \App\Auth\AccessPolicy::resolveRegionIdForRead($user, getCurrentRegionId());
    } catch (\App\Auth\AccessDenied $e) {
        denyWithStatus($e->getStatus(), $e->getMessage());
    }
}

function setActiveRegionId(?int $regionId): void
{
    if ($regionId === null || $regionId <= 0) {
        unset($_SESSION['active_region_id']);
        return;
    }
    if (!canAccessRegion($regionId)) {
        denyWithStatus(403, 'Доступ к этому региону запрещён');
    }
    $_SESSION['active_region_id'] = $regionId;
}

function getActiveRegionId(): ?int
{
    return isset($_SESSION['active_region_id']) ? (int)$_SESSION['active_region_id'] : null;
}

function enrichUserPayload(array $user): array
{
    global $db;
    $role = \App\Auth\AccessPolicy::normalizeRole($user['role'] ?? 'viewer');
    $user['role'] = $role;
    $user['is_admin'] = \App\Auth\AccessPolicy::isAdmin($role);
    $user['can_write'] = \App\Auth\AccessPolicy::canWrite($role);
    $user['can_delete'] = \App\Auth\AccessPolicy::canDelete($role);
    $user['can_export'] = \App\Auth\AccessPolicy::canExport($role);
    $user['active_region_id'] = getActiveRegionId();

    $regionId = $user['region_id'] ?? getActiveRegionId();
    if ($regionId) {
        $regionCacheKey = '_reg_' . (int)$regionId;
        if (array_key_exists($regionCacheKey, $_SESSION)) {
            $user['region'] = $_SESSION[$regionCacheKey];
        } else {
            $stmt = $db->prepare('SELECT id, name_kz, name_ru, code, is_active FROM regions WHERE id = ?');
            $stmt->execute([(int)$regionId]);
            $regionData = $stmt->fetch() ?: null;
            $_SESSION[$regionCacheKey] = $regionData;
            $user['region'] = $regionData;
        }
    } else {
        $user['region'] = null;
    }

    // Resolve linked OS member (for "My Letters" feature)
    if (empty($user['member_id'])) {
        $userId = (int)($user['id'] ?? 0);
        $memberCacheKey = '_mbr_' . $userId;
        if ($userId > 0 && array_key_exists($memberCacheKey, $_SESSION)) {
            $cached = (int)$_SESSION[$memberCacheKey];
            $user['member_id'] = $cached > 0 ? $cached : null;
        } else {
            try {
                $memberRegion = $regionId ?: ($user['region_id'] ?? null);
                if ($memberRegion && !empty($user['full_name'])) {
                    $mStmt = $db->prepare('SELECT id FROM os_members WHERE full_name = ? AND region_id = ? AND status = ? LIMIT 1');
                    $mStmt->execute([$user['full_name'], (int)$memberRegion, 'active']);
                    $mRow = $mStmt->fetch();
                    $memberId = $mRow ? (int)$mRow['id'] : 0;
                    if ($userId > 0) {
                        $_SESSION[$memberCacheKey] = $memberId;
                    }
                    $user['member_id'] = $memberId ?: null;
                } else {
                    $user['member_id'] = null;
                }
            } catch (\Throwable $e) {
                $user['member_id'] = null;
            }
        }
    } else {
        $user['member_id'] = (int)$user['member_id'];
    }

    return $user;
}

function canAccessRegion($regionId): bool
{
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    return \App\Auth\AccessPolicy::canAccessRegion($user, $regionId);
}

/**
 * Validate password strength. Returns error message or null if valid.
 */
function validatePasswordStrength(string $pass): ?string
{
    if (strlen($pass) < 8) return 'Пароль должен содержать минимум 8 символов';
    if (strlen($pass) > 72) return 'Пароль не должен превышать 72 символа';
    if (!preg_match('/[A-ZА-ЯЁ]/u', $pass)) return 'Пароль должен содержать заглавную букву';
    if (!preg_match('/[0-9]/', $pass)) return 'Пароль должен содержать цифру';
    return null;
}

