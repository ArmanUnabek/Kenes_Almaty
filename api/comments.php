<?php
/**
 * Комментарии к письмам (GET/POST/DELETE).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Middleware\CsrfMiddleware;

header('Content-Type: application/json; charset=utf-8');

checkAuth();

$db  = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Ensure letter_comments table exists (runtime migration)
static $tableChecked = false;
if (!$tableChecked) {
    $tableChecked = true;
    $db->exec("
        CREATE TABLE IF NOT EXISTS letter_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            letter_type ENUM('incoming','outgoing') NOT NULL,
            letter_id INT NOT NULL,
            user_id INT NOT NULL,
            comment TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_letter (letter_type, letter_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

switch ($method) {
    case 'GET':
        handleGetComments($db);
        break;
    case 'POST':
        requireWriteAccess();
        CsrfMiddleware::requireVerification();
        handlePostComment($db);
        break;
    case 'DELETE':
        CsrfMiddleware::requireVerification();
        handleDeleteComment($db);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Метод не поддерживается'], JSON_ENCODE_FLAGS);
}

/**
 * Проверяет, что письмо существует и принадлежит доступному пользователю региону.
 * Прерывает выполнение с 404/403 при отсутствии доступа.
 */
function assertLetterAccess(\PDO $db, string $letterType, int $letterId): void
{
    $table = $letterType === 'incoming' ? 'incoming_letters' : 'outgoing_letters';
    $stmt = $db->prepare("SELECT region_id FROM {$table} WHERE id = ?");
    $stmt->execute([$letterId]);
    $regionId = $stmt->fetchColumn();
    if ($regionId === false) {
        http_response_code(404);
        echo json_encode(['error' => 'Письмо не найдено'], JSON_ENCODE_FLAGS);
        exit;
    }
    if ((int)$regionId > 0 && !canAccessRegion((int)$regionId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ к письму запрещён'], JSON_ENCODE_FLAGS);
        exit;
    }
}

function handleGetComments(\PDO $db): void
{
    $letterType = $_GET['letter_type'] ?? '';
    $letterId   = (int)($_GET['letter_id'] ?? 0);

    if (!in_array($letterType, ['incoming', 'outgoing'], true) || $letterId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Укажите letter_type и letter_id'], JSON_ENCODE_FLAGS);
        return;
    }

    assertLetterAccess($db, $letterType, $letterId);

    $stmt = $db->prepare("
        SELECT c.id, c.letter_type, c.letter_id, c.comment, c.created_at,
               u.id AS user_id, u.full_name AS user_name, u.username AS user_login
        FROM letter_comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.letter_type = ? AND c.letter_id = ?
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([$letterType, $letterId]);
    echo json_encode($stmt->fetchAll(), JSON_ENCODE_FLAGS);
}

function handlePostComment(\PDO $db): void
{
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $letterType = $data['letter_type'] ?? '';
    $letterId   = (int)($data['letter_id'] ?? 0);
    $comment    = trim($data['comment'] ?? '');

    if (!in_array($letterType, ['incoming', 'outgoing'], true) || $letterId <= 0 || $comment === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Поля letter_type, letter_id и comment обязательны'], JSON_ENCODE_FLAGS);
        return;
    }

    if (mb_strlen($comment) > 2000) {
        http_response_code(422);
        echo json_encode(['error' => 'Комментарий слишком длинный (макс. 2000 символов)'], JSON_ENCODE_FLAGS);
        return;
    }

    assertLetterAccess($db, $letterType, $letterId);

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $db->prepare("
        INSERT INTO letter_comments (letter_type, letter_id, user_id, comment)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$letterType, $letterId, $userId, $comment]);
    $id = (int)$db->lastInsertId();

    $stmtGet = $db->prepare("
        SELECT c.id, c.letter_type, c.letter_id, c.comment, c.created_at,
               u.id AS user_id, u.full_name AS user_name, u.username AS user_login
        FROM letter_comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ?
    ");
    $stmtGet->execute([$id]);
    http_response_code(201);
    echo json_encode($stmtGet->fetch(), JSON_ENCODE_FLAGS);
}

function handleDeleteComment(\PDO $db): void
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID не указан'], JSON_ENCODE_FLAGS);
        return;
    }

    $stmt = $db->prepare('SELECT user_id, letter_type, letter_id FROM letter_comments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Комментарий не найден'], JSON_ENCODE_FLAGS);
        return;
    }

    assertLetterAccess($db, (string)$row['letter_type'], (int)$row['letter_id']);

    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    $isAdmin       = isAdmin();

    if ((int)$row['user_id'] !== $currentUserId && !$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Нет прав на удаление этого комментария'], JSON_ENCODE_FLAGS);
        return;
    }

    $db->prepare('DELETE FROM letter_comments WHERE id = ?')->execute([$id]);
    echo json_encode(['message' => 'Комментарий удалён'], JSON_ENCODE_FLAGS);
}
