<?php
/**
 * Шаблоны писем (GET/POST/PUT/DELETE).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Middleware\CsrfMiddleware;

header('Content-Type: application/json; charset=utf-8');

checkAuth();

$db     = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Ensure letter_templates table exists (runtime migration, driver-aware)
$driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
if ($driver === 'sqlite') {
    $db->exec("
        CREATE TABLE IF NOT EXISTS letter_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            region_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            letter_type TEXT NOT NULL,
            organization VARCHAR(255),
            subject TEXT,
            note TEXT,
            category TEXT DEFAULT 'KK',
            created_by INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} elseif ($driver === 'pgsql') {
    $db->exec("
        CREATE TABLE IF NOT EXISTS letter_templates (
            id SERIAL PRIMARY KEY,
            region_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            letter_type VARCHAR(20) NOT NULL,
            organization VARCHAR(255),
            subject TEXT,
            note TEXT,
            category VARCHAR(5) DEFAULT 'KK',
            created_by INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} else {
    $db->exec("
        CREATE TABLE IF NOT EXISTS letter_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            region_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            letter_type ENUM('incoming','outgoing') NOT NULL,
            organization VARCHAR(255),
            subject TEXT,
            note TEXT,
            category ENUM('KK','N','JT','ZT') DEFAULT 'KK',
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_region_type (region_id, letter_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

switch ($method) {
    case 'GET':
        handleGetTemplates($db);
        break;
    case 'POST':
        requireWriteAccess();
        CsrfMiddleware::requireVerification();
        handleCreateTemplate($db);
        break;
    case 'PUT':
        requireWriteAccess();
        CsrfMiddleware::requireVerification();
        handleUpdateTemplate($db);
        break;
    case 'DELETE':
        requireWriteAccess();
        CsrfMiddleware::requireVerification();
        handleDeleteTemplate($db);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Метод не поддерживается'], JSON_ENCODE_FLAGS);
}

function getRegionId(\PDO $db): ?int
{
    $user = getCurrentUser();
    if (!$user) {
        return null;
    }
    if (normalizeRole($user['role'] ?? '') === 'admin') {
        $rid = getCurrentRegionId();
        return $rid ?: null;
    }
    return $user['region_id'] ? (int)$user['region_id'] : null;
}

function handleGetTemplates(\PDO $db): void
{
    $letterType = $_GET['letter_type'] ?? null;
    $regionId   = getRegionId($db);

    $conditions = [];
    $params     = [];

    if ($regionId) {
        $conditions[] = 'region_id = ?';
        $params[] = $regionId;
    }
    if ($letterType && in_array($letterType, ['incoming', 'outgoing'], true)) {
        $conditions[] = 'letter_type = ?';
        $params[] = $letterType;
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $stmt  = $db->prepare("SELECT * FROM letter_templates $where ORDER BY name ASC");
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(), JSON_ENCODE_FLAGS);
}

function handleCreateTemplate(\PDO $db): void
{
    $data       = json_decode(file_get_contents('php://input'), true) ?: [];
    $regionId   = resolveRegionIdForWrite(isset($data['region_id']) ? (int)$data['region_id'] : null);
    $name       = trim($data['name'] ?? '');
    $letterType = $data['letter_type'] ?? '';

    if ($name === '' || !in_array($letterType, ['incoming', 'outgoing'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Поля name и letter_type обязательны'], JSON_ENCODE_FLAGS);
        return;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $stmt   = $db->prepare("
        INSERT INTO letter_templates (region_id, name, letter_type, organization, subject, note, category, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $regionId,
        $name,
        $letterType,
        $data['organization'] ?? null,
        $data['subject'] ?? null,
        $data['note'] ?? null,
        in_array($data['category'] ?? '', ['KK','N','JT','ZT']) ? $data['category'] : 'KK',
        $userId,
    ]);
    $id = (int)$db->lastInsertId();
    http_response_code(201);
    $stmtGet = $db->prepare('SELECT * FROM letter_templates WHERE id = ?');
    $stmtGet->execute([$id]);
    echo json_encode($stmtGet->fetch(), JSON_ENCODE_FLAGS);
}

function handleUpdateTemplate(\PDO $db): void
{
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id   = (int)($data['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID не указан'], JSON_ENCODE_FLAGS);
        return;
    }

    $stmt = $db->prepare('SELECT region_id FROM letter_templates WHERE id = ?');
    $stmt->execute([$id]);
    $row  = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Шаблон не найден'], JSON_ENCODE_FLAGS);
        return;
    }
    if (!canAccessRegion((int)$row['region_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ к шаблону запрещён'], JSON_ENCODE_FLAGS);
        return;
    }

    $name = trim($data['name'] ?? '');
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Поле name обязательно'], JSON_ENCODE_FLAGS);
        return;
    }

    $db->prepare("
        UPDATE letter_templates SET name=?, organization=?, subject=?, note=?, category=?, updated_at=CURRENT_TIMESTAMP
        WHERE id=?
    ")->execute([
        $name,
        $data['organization'] ?? null,
        $data['subject'] ?? null,
        $data['note'] ?? null,
        in_array($data['category'] ?? '', ['KK','N','JT','ZT']) ? $data['category'] : 'KK',
        $id,
    ]);

    $stmtGet = $db->prepare('SELECT * FROM letter_templates WHERE id = ?');
    $stmtGet->execute([$id]);
    echo json_encode($stmtGet->fetch(), JSON_ENCODE_FLAGS);
}

function handleDeleteTemplate(\PDO $db): void
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID не указан'], JSON_ENCODE_FLAGS);
        return;
    }

    $stmt = $db->prepare('SELECT region_id FROM letter_templates WHERE id = ?');
    $stmt->execute([$id]);
    $regionId = $stmt->fetchColumn();
    if ($regionId === false) {
        http_response_code(404);
        echo json_encode(['error' => 'Шаблон не найден'], JSON_ENCODE_FLAGS);
        return;
    }
    if (!canAccessRegion((int)$regionId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ к шаблону запрещён'], JSON_ENCODE_FLAGS);
        return;
    }

    $db->prepare('DELETE FROM letter_templates WHERE id = ?')->execute([$id]);
    echo json_encode(['message' => 'Шаблон удалён'], JSON_ENCODE_FLAGS);
}
