<?php
/**
 * User self-service profile endpoint.
 * GET  /api/profile.php          — return current user profile
 * PUT  /api/profile.php          — update display name and/or password (CSRF required)
 * POST /api/profile.php?action=photo — upload profile photo (CSRF required)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/Middleware/CsrfMiddleware.php';

use App\Middleware\CsrfMiddleware;

header('Content-Type: application/json; charset=utf-8');

if (function_exists('configureSessionCookie')) {
    configureSessionCookie();
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
CsrfMiddleware::init();

checkAuth();
$db     = getDBConnection();
$user   = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    handleGet($db, $userId);
} elseif ($method === 'PUT') {
    CsrfMiddleware::requireVerification();
    handleUpdate($db, $userId);
} elseif ($method === 'POST' && $action === 'photo') {
    CsrfMiddleware::requireVerification();
    handlePhotoUpload($db, $userId);
} elseif ($method === 'DELETE' && $action === 'photo') {
    CsrfMiddleware::requireVerification();
    handlePhotoDelete($db, $userId);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не поддерживается'], JSON_ENCODE_FLAGS);
}

function handleGet(\PDO $db, int $userId): void
{
    $stmt = $db->prepare('SELECT id, username, full_name, email, role, region_id, totp_enabled, telegram_chat_id, photo, last_login, created_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Пользователь не найден'], JSON_ENCODE_FLAGS);
        return;
    }
    unset($user['password_hash']);
    $user['telegram_linked'] = !empty($user['telegram_chat_id']);
    unset($user['telegram_chat_id']);
    echo json_encode($user, JSON_ENCODE_FLAGS);
}

function handleUpdate(\PDO $db, int $userId): void
{
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $fields  = [];
    $params  = [];
    $errors  = [];

    if (isset($data['full_name'])) {
        $name = trim($data['full_name']);
        if (strlen($name) < 2 || strlen($name) > 255) {
            $errors['full_name'] = 'Имя должно быть от 2 до 255 символов';
        } else {
            $fields[] = 'full_name = ?';
            $params[] = $name;
        }
    }

    if (isset($data['email'])) {
        $email = trim($data['email']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Некорректный email';
        } else {
            $fields[] = 'email = ?';
            $params[] = $email !== '' ? $email : null;
        }
    }

    if (!empty($data['password'])) {
        $pass    = $data['password'];
        $current = $data['current_password'] ?? '';

        // Verify current password
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($current, $row['password_hash'])) {
            $errors['current_password'] = 'Текущий пароль введён неверно';
        } elseif (strlen($pass) < 8) {
            $errors['password'] = 'Новый пароль должен содержать минимум 8 символов';
        } else {
            $fields[] = 'password_hash = ?';
            $params[] = password_hash($pass, PASSWORD_DEFAULT);
        }
    }

    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['error' => 'Ошибка валидации', 'errors' => $errors], JSON_ENCODE_FLAGS);
        return;
    }

    if (empty($fields)) {
        echo json_encode(['message' => 'Нет изменений для сохранения'], JSON_ENCODE_FLAGS);
        return;
    }

    $fields[]  = 'updated_at = NOW()';
    $params[]  = $userId;
    $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')
       ->execute($params);

    echo json_encode(['message' => 'Профиль обновлён'], JSON_ENCODE_FLAGS);
}

function handlePhotoUpload(\PDO $db, int $userId): void
{
    if (empty($_FILES['photo'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Файл не загружен'], JSON_ENCODE_FLAGS);
        return;
    }

    $file = $_FILES['photo'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Ошибка загрузки файла'], JSON_ENCODE_FLAGS);
        return;
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode(['error' => 'Файл превышает лимит 5 МБ'], JSON_ENCODE_FLAGS);
        return;
    }

    // Validate MIME type using finfo (not client-provided)
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($allowed[$mimeType])) {
        http_response_code(422);
        echo json_encode(['error' => 'Разрешены только JPEG и PNG'], JSON_ENCODE_FLAGS);
        return;
    }

    $dir = APP_ROOT . '/uploads/photos';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        http_response_code(500);
        echo json_encode(['error' => 'Не удалось создать директорию'], JSON_ENCODE_FLAGS);
        return;
    }

    // Remove old photo
    $stmtOld = $db->prepare('SELECT photo FROM users WHERE id = ?');
    $stmtOld->execute([$userId]);
    $oldPhoto = $stmtOld->fetchColumn();
    if ($oldPhoto) {
        $oldFull = realpath(APP_ROOT . '/' . ltrim((string)$oldPhoto, '/'));
        $rootDir = realpath($dir);
        if ($oldFull && $rootDir && str_starts_with($oldFull, $rootDir . DIRECTORY_SEPARATOR)) {
            @unlink($oldFull);
        }
    }

    $ext      = $allowed[$mimeType];
    $filename = 'user_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest     = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['error' => 'Ошибка сохранения файла'], JSON_ENCODE_FLAGS);
        return;
    }

    $relativePath = 'uploads/photos/' . $filename;
    $db->prepare('UPDATE users SET photo = ?, updated_at = NOW() WHERE id = ?')
       ->execute([$relativePath, $userId]);

    echo json_encode(['photo' => $relativePath, 'message' => 'Фото обновлено'], JSON_ENCODE_FLAGS);
}

function handlePhotoDelete(\PDO $db, int $userId): void
{
    $stmt = $db->prepare('SELECT photo FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $photo = $stmt->fetchColumn();
    if ($photo) {
        $dir      = realpath(APP_ROOT . '/uploads/photos');
        $fullPath = realpath(APP_ROOT . '/' . ltrim((string)$photo, '/'));
        if ($fullPath && $dir && str_starts_with($fullPath, $dir . DIRECTORY_SEPARATOR)) {
            @unlink($fullPath);
        }
        $db->prepare('UPDATE users SET photo = NULL, updated_at = NOW() WHERE id = ?')
           ->execute([$userId]);
    }
    echo json_encode(['message' => 'Фото удалено'], JSON_ENCODE_FLAGS);
}
