<?php
require_once __DIR__ . '/../db.php';

configureSessionCookie();
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = getDBConnection();
    $region_id = $_SESSION['region_id'] ?? 1;
    $method = $_SERVER['REQUEST_METHOD'];
    $commission_id = $_GET['commission_id'] ?? null;
    $id = $_GET['id'] ?? null;

    if ($method === 'GET') {
        if ($id) {
            // Получить одного члена
            $stmt = $db->prepare('
                SELECT m.*, c.name as commission_name, c.color as commission_color
                FROM os_members m
                LEFT JOIN commissions c ON m.commission_id = c.id
                WHERE m.id = ? AND m.region_id = ?
            ');
            $stmt->execute([$id, $region_id]);
            $member = $stmt->fetch();

            if (!$member) {
                http_response_code(404);
                echo json_encode(['error' => 'Member not found'], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode($member, JSON_UNESCAPED_UNICODE);
        } else {
            // Получить всех членов
            $query = 'SELECT m.*, c.name as commission_name, c.color as commission_color FROM os_members m LEFT JOIN commissions c ON m.commission_id = c.id WHERE m.region_id = ?';
            $params = [$region_id];

            if ($commission_id) {
                $query .= ' AND m.commission_id = ?';
                $params[] = $commission_id;
            }

            $query .= ' ORDER BY m.full_name';

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $members = $stmt->fetchAll();

            echo json_encode($members, JSON_UNESCAPED_UNICODE);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    http_response_code(500);
    error_log('members failed: ' . $e->getMessage());
    echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_UNESCAPED_UNICODE);
}
