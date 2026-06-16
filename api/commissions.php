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
    $id = $_GET['id'] ?? null;

    if ($method === 'GET') {
        if ($id) {
            // Получить одну комиссию
            $stmt = $db->prepare('SELECT * FROM commissions WHERE id = ? AND region_id = ?');
            $stmt->execute([$id, $region_id]);
            $commission = $stmt->fetch();

            if (!$commission) {
                http_response_code(404);
                echo json_encode(['error' => 'Commission not found'], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode($commission, JSON_UNESCAPED_UNICODE);
        } else {
            // Получить все комиссии
            $stmt = $db->prepare('SELECT * FROM commissions WHERE region_id = ? ORDER BY sort_order, name');
            $stmt->execute([$region_id]);
            $commissions = $stmt->fetchAll();

            echo json_encode($commissions, JSON_UNESCAPED_UNICODE);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    http_response_code(500);
    error_log('commissions failed: ' . $e->getMessage());
    echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_UNESCAPED_UNICODE);
}
