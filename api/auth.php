<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/Middleware/CsrfMiddleware.php';

use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? 'check';

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

    $stmt = $db->prepare('SELECT id, username, full_name, role, region_id, password_hash FROM users WHERE username = ? AND is_active = TRUE');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        try {
            $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, ip_address) VALUES (NULL, ?, ?, ?)')
                ->execute(['login_failed', 'user:' . $username, $ip]);
        } catch (\Throwable $e) {
            error_log('Failed to log login attempt: ' . $e->getMessage());
        }
        http_response_code(401);
        echo json_encode(['error' => 'Неверный логин или пароль'], JSON_ENCODE_FLAGS);
        return;
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['region_id'] = $user['region_id'];
    $_SESSION['last_activity_at'] = time();

    if (normalizeRole($user['role'] ?? '') === 'admin') {
        $defaultRegion = $user['region_id'] ? (int)$user['region_id'] : 1;
        $_SESSION['active_region_id'] = $defaultRegion;
    }

    $now = date('Y-m-d H:i:s');
    $db->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([$now, $user['id']]);

    $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, ip_address) VALUES (?, ?, ?, ?)')
        ->execute([$user['id'], 'login', 'user', $_SERVER['REMOTE_ADDR'] ?? '']);

    unset($user['password_hash']);
    $user = enrichUserPayload($user);

    echo json_encode([
        'authenticated' => true,
        'user' => $user
    ], JSON_ENCODE_FLAGS);
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

    $stmt = $db->prepare('SELECT id, username, full_name, role, region_id, email FROM users WHERE id = ? AND is_active = TRUE');
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
