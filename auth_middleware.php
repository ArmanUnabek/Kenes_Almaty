<?php
require_once 'config.php';

if (function_exists('configureSessionCookie')) {
    configureSessionCookie();
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security headers for all authenticated responses
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: blob:; connect-src 'self' wss://ws-*.pusher.com https://sockjs.pusher.com; font-src 'self' https://cdn.jsdelivr.net; frame-ancestors 'none';");
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

function checkAuth(): void
{
    if (!isset($_SESSION['user_id'])) {
        denyWithStatus(401, 'Требуется авторизация');
    }

    $lastActivity = (int)($_SESSION['last_activity_at'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > SESSION_IDLE_TIMEOUT_SECONDS) {
        $_SESSION = [];
        session_destroy();
        denyWithStatus(401, 'Сессия истекла по неактивности');
    }
    $_SESSION['last_activity_at'] = time();
}

function getCurrentUser(): ?array
{
    global $db;
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $db->prepare('SELECT id, username, full_name, role, region_id, email, is_active, last_login, created_at, updated_at FROM users WHERE id = ? AND is_active = TRUE');
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
        $stmt = $db->prepare('SELECT id, name_kz, name_ru, code, is_active FROM regions WHERE id = ?');
        $stmt->execute([(int)$regionId]);
        $user['region'] = $stmt->fetch() ?: null;
    } else {
        $user['region'] = null;
    }

    // Resolve linked OS member (for "My Letters" feature)
    if (empty($user['member_id'])) {
        try {
            $memberRegion = $regionId ?: ($user['region_id'] ?? null);
            if ($memberRegion && !empty($user['full_name'])) {
                $mStmt = $db->prepare('SELECT id FROM os_members WHERE full_name = ? AND region_id = ? AND status = ? LIMIT 1');
                $mStmt->execute([$user['full_name'], (int)$memberRegion, 'active']);
                $mRow = $mStmt->fetch();
                $user['member_id'] = $mRow ? (int)$mRow['id'] : null;
            } else {
                $user['member_id'] = null;
            }
        } catch (\Throwable $e) {
            $user['member_id'] = null;
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

