<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Middleware/CsrfMiddleware.php';

use App\Middleware\CsrfMiddleware;

configureSessionCookie();
session_start();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'check';

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

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Логин и пароль обязательны'
        ], JSON_ENCODE_FLAGS);
        return;
    }

    $stmt = $db->prepare('SELECT id, username, full_name, role, region_id, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode([
            'error' => 'Неверный логин или пароль'
        ], JSON_ENCODE_FLAGS);
        return;
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['region_id'] = $user['region_id'];
    $_SESSION['last_activity_at'] = time();

    $now = date('Y-m-d H:i:s');
    $db->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([$now, $user['id']]);

    $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, ip_address) VALUES (?, ?, ?, ?)')
        ->execute([$user['id'], 'login', 'user', $_SERVER['REMOTE_ADDR'] ?? '']);

    echo json_encode([
        'authenticated' => true,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
            'region_id' => $user['region_id']
        ]
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

function handleCheck($db) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'authenticated' => false
        ], JSON_ENCODE_FLAGS);
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

    $stmt = $db->prepare('SELECT id, username, full_name, role, region_id FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION = [];
        session_destroy();
        http_response_code(401);
        echo json_encode([
            'authenticated' => false
        ], JSON_ENCODE_FLAGS);
        return;
    }

    $_SESSION['last_activity_at'] = time();

    echo json_encode([
        'authenticated' => true,
        'user' => $user
    ], JSON_ENCODE_FLAGS);
}
