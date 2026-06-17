<?php
require_once 'config.php';

if (function_exists('configureSessionCookie')) {
    configureSessionCookie();
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
    $role = strtolower((string)$role);
    // Backward compatibility: old "manager" behaves like "moderator".
    if ($role === 'manager') {
        return 'moderator';
    }
    if (in_array($role, ['admin', 'moderator', 'viewer'], true)) {
        return $role;
    }
    return 'viewer';
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

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = TRUE");
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
    $role = normalizeRole($user['role'] ?? 'viewer');
    if (!in_array($role, $allowedRoles, true)) {
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
    return (bool)$user && normalizeRole($user['role'] ?? '') === 'admin';
}

function isManager(): bool
{
    // Backward compatibility with old function name.
    $user = getCurrentUser();
    return (bool)$user && in_array(normalizeRole($user['role'] ?? ''), ['admin', 'moderator'], true);
}

function canWrite(): bool
{
    return isManager();
}

function canDelete(): bool
{
    return isAdmin();
}

function getCurrentRegionId(): ?int
{
    $user = getCurrentUser();
    if (!$user) {
        return null;
    }

    if (isAdmin()) {
        if (isset($_SESSION['active_region_id']) && $_SESSION['active_region_id'] !== '' && $_SESSION['active_region_id'] !== null) {
            return (int)$_SESSION['active_region_id'];
        }
        return null;
    }

    $regionId = $user['region_id'] ?? null;
    return $regionId ? (int)$regionId : null;
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
    $role = normalizeRole($user['role'] ?? 'viewer');
    $user['role'] = $role;
    $user['is_admin'] = ($role === 'admin');
    $user['can_write'] = in_array($role, ['admin', 'moderator'], true);
    $user['can_delete'] = ($role === 'admin');
    $user['active_region_id'] = getActiveRegionId();

    $regionId = $user['region_id'] ?? getActiveRegionId();
    if ($regionId) {
        $stmt = $db->prepare('SELECT id, name_kz, name_ru, code, is_active FROM regions WHERE id = ?');
        $stmt->execute([(int)$regionId]);
        $user['region'] = $stmt->fetch() ?: null;
    } else {
        $user['region'] = null;
    }

    return $user;
}

function canAccessRegion($regionId): bool
{
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    if (normalizeRole($user['role'] ?? '') === 'admin') {
        return true;
    }
    return (int)$regionId === (int)($user['region_id'] ?? 0);
}

