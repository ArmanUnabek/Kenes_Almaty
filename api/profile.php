<?php
/**
 * User self-service profile endpoint.
 * GET    /api/profile.php               — return current user profile
 * PUT    /api/profile.php               — update display name and/or password (CSRF required)
 * POST   /api/profile.php?action=photo  — upload profile photo (CSRF required)
 * DELETE /api/profile.php?action=photo  — remove profile photo (CSRF required)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';

use App\ApiController;

class ProfileController extends ApiController
{
    public function handle(): void
    {
        try {
            $this->requireAuth();
            $userId = (int)($this->currentUser['id'] ?? 0);
            $method = $_SERVER['REQUEST_METHOD'];
            $action = $this->getQueryParam('action', '');

            if ($method === 'GET') {
                $this->handleGet($userId);
            } elseif ($method === 'PUT') {
                $this->requireCsrf();
                $this->handleUpdate($userId);
            } elseif ($method === 'POST' && $action === 'photo') {
                $this->requireCsrf();
                $this->handlePhotoUpload($userId);
            } elseif ($method === 'DELETE' && $action === 'photo') {
                $this->requireCsrf();
                $this->handlePhotoDelete($userId);
            } else {
                $this->error('Метод не поддерживается', 405);
            }
        } catch (\Throwable $e) {
            $this->handleException($e, 'ProfileController');
        }
    }

    private function handleGet(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT id, username, full_name, email, role, region_id, totp_enabled, telegram_chat_id, photo, last_login, created_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            $this->error('Пользователь не найден', 404);
        }
        unset($user['password_hash']);
        $user['telegram_linked'] = !empty($user['telegram_chat_id']);
        unset($user['telegram_chat_id']);
        $this->json($user);
    }

    private function handleUpdate(int $userId): void
    {
        $data = $this->getJsonInput() ?? [];

        $fields = [];
        $params = [];
        $errors = [];

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
            $pass = $data['password'];
            $current = $data['current_password'] ?? '';

            $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = ?');
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
            $this->validationError($errors);
        }

        if (empty($fields)) {
            $this->json(['message' => 'Нет изменений для сохранения']);
        }

        $fields[] = 'updated_at = NOW()';
        $params[] = $userId;
        $this->db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($params);

        $this->json(['message' => 'Профиль обновлён']);
    }

    private function handlePhotoUpload(int $userId): void
    {
        if (empty($_FILES['photo'])) {
            $this->error('Файл не загружен', 400);
        }

        $file = $_FILES['photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->error('Ошибка загрузки файла', 400);
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            $this->error('Файл превышает лимит 5 МБ', 422);
        }

        // Validate MIME type using finfo (not client-provided)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (!isset($allowed[$mimeType])) {
            $this->error('Разрешены только JPEG и PNG', 422);
        }

        $dir = APP_ROOT . '/uploads/photos';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->error('Не удалось создать директорию', 500);
        }

        // Remove old photo (confined to the uploads directory)
        $stmtOld = $this->db->prepare('SELECT photo FROM users WHERE id = ?');
        $stmtOld->execute([$userId]);
        $oldPhoto = $stmtOld->fetchColumn();
        if ($oldPhoto) {
            $oldFull = realpath(APP_ROOT . '/' . ltrim((string)$oldPhoto, '/'));
            $rootDir = realpath($dir);
            if ($oldFull && $rootDir && str_starts_with($oldFull, $rootDir . DIRECTORY_SEPARATOR)) {
                @unlink($oldFull);
            }
        }

        $ext = $allowed[$mimeType];
        // Fully-random, unguessable filename (no user id in the path).
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->error('Ошибка сохранения файла', 500);
        }

        $relativePath = 'uploads/photos/' . $filename;
        $this->db->prepare('UPDATE users SET photo = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$relativePath, $userId]);

        $this->json(['photo' => $relativePath, 'message' => 'Фото обновлено']);
    }

    private function handlePhotoDelete(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT photo FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $photo = $stmt->fetchColumn();
        if ($photo) {
            $dir = realpath(APP_ROOT . '/uploads/photos');
            $fullPath = realpath(APP_ROOT . '/' . ltrim((string)$photo, '/'));
            if ($fullPath && $dir && str_starts_with($fullPath, $dir . DIRECTORY_SEPARATOR)) {
                @unlink($fullPath);
            }
            $this->db->prepare('UPDATE users SET photo = NULL, updated_at = NOW() WHERE id = ?')
                ->execute([$userId]);
        }
        $this->json(['message' => 'Фото удалено']);
    }
}

$controller = new ProfileController();
$controller->handle();
