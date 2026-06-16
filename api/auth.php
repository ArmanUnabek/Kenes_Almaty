<?php
require_once __DIR__ . '/../db.php';
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
    ], JSON_UNESCAPED_UNICODE);
}

function handleLogin($db) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Логин и пароль обязательны'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Получить пользователя
    $stmt = $db->prepare('SELECT id, username, full_name, role, region_id, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode([
            'error' => 'Неверный логин или пароль'
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Защита от session fixation: новый идентификатор сессии после входа
    session_regenerate_id(true);

    // Создать сессию
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['region_id'] = $user['region_id'];

    // Обновить last_login
    $now = date('Y-m-d H:i:s');
    $db->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([$now, $user['id']]);

    // Логирование
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
    ], JSON_UNESCAPED_UNICODE);
}

function handleLogout($db) {
    if (isset($_SESSION['user_id'])) {
        $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, ip_address) VALUES (?, ?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'logout', 'user', $_SERVER['REMOTE_ADDR'] ?? '']);
    }

    session_destroy();

    echo json_encode([
        'authenticated' => false,
        'message' => 'Вы вышли из системы'
    ], JSON_UNESCAPED_UNICODE);
}

function handleCsrf($db) {
    // Сессионный CSRF-токен, согласованный с CsrfMiddleware::requireVerification().
    echo json_encode([
        'csrf_token' => CsrfMiddleware::getToken()
    ], JSON_UNESCAPED_UNICODE);
}

function handleCheck($db) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'authenticated' => false
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $db->prepare('SELECT id, username, full_name, role, region_id FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        http_response_code(401);
        echo json_encode([
            'authenticated' => false
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    echo json_encode([
        'authenticated' => true,
        'user' => $user
    ], JSON_UNESCAPED_UNICODE);
}
