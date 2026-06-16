<?php
require_once '../config.php';
require_once '../auth_middleware.php';

use App\Middleware\CsrfMiddleware;

$method = $_SERVER['REQUEST_METHOD'];
$db = getDBConnection();

// Доступ к регионам только для авторизованных пользователей
checkAuth();

// Мутации только для админов и под защитой CSRF
if ($method !== 'GET') {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещен']);
        exit;
    }
    CsrfMiddleware::requireVerification();
}

switch ($method) {
    case 'GET':
        $id = $_GET['id'] ?? null;
        $code = $_GET['code'] ?? null;
        $activeOnly = isset($_GET['active_only']) && $_GET['active_only'] === '1';
        
        if ($id) {
            $stmt = $db->prepare("SELECT * FROM regions WHERE id = ?");
            $stmt->execute([$id]);
            $region = $stmt->fetch();
            
            if ($region) {
                // Получить статистику региона
                $stats = [
                    'members_count' => 0,
                    'commissions_count' => 0,
                    'incoming_letters_count' => 0,
                    'outgoing_letters_count' => 0
                ];
                
                $stmt2 = $db->prepare("SELECT COUNT(*) as count FROM os_members WHERE region_id = ? AND status = 'active'");
                $stmt2->execute([$id]);
                $stats['members_count'] = $stmt2->fetch()['count'];
                
                $stmt3 = $db->prepare("SELECT COUNT(*) as count FROM commissions WHERE region_id = ?");
                $stmt3->execute([$id]);
                $stats['commissions_count'] = $stmt3->fetch()['count'];
                
                $stmt4 = $db->prepare("SELECT COUNT(*) as count FROM incoming_letters WHERE region_id = ?");
                $stmt4->execute([$id]);
                $stats['incoming_letters_count'] = $stmt4->fetch()['count'];
                
                $stmt5 = $db->prepare("SELECT COUNT(*) as count FROM outgoing_letters WHERE region_id = ?");
                $stmt5->execute([$id]);
                $stats['outgoing_letters_count'] = $stmt5->fetch()['count'];
                
                $region['stats'] = $stats;
                echo json_encode($region);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Регион не найден']);
            }
        } elseif ($code) {
            $stmt = $db->prepare("SELECT * FROM regions WHERE code = ?");
            $stmt->execute([$code]);
            $region = $stmt->fetch();
            echo json_encode($region ?: null);
        } else {
            $sql = "SELECT * FROM regions";
            if ($activeOnly) {
                $sql .= " WHERE is_active = TRUE";
            }
            $sql .= " ORDER BY name_ru";
            
            $stmt = $db->query($sql);
            $regions = $stmt->fetchAll();
            echo json_encode($regions);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $stmt = $db->prepare("
            INSERT INTO regions (name_kz, name_ru, code, is_active, settings)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['name_kz'] ?? '',
            $data['name_ru'] ?? '',
            $data['code'] ?? '',
            $data['is_active'] ?? TRUE,
            json_encode($data['settings'] ?? [])
        ]);
        
        $id = $db->lastInsertId();
        echo json_encode(['id' => $id, 'message' => 'Регион успешно создан']);
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID не указан']);
            break;
        }
        
        $stmt = $db->prepare("
            UPDATE regions 
            SET name_kz = ?, name_ru = ?, code = ?, is_active = ?, settings = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $data['name_kz'] ?? '',
            $data['name_ru'] ?? '',
            $data['code'] ?? '',
            $data['is_active'] ?? TRUE,
            json_encode($data['settings'] ?? []),
            $id
        ]);
        
        echo json_encode(['message' => 'Регион успешно обновлен']);
        break;
        
    case 'DELETE':
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID не указан']);
            break;
        }
        
        $stmt = $db->prepare("UPDATE regions SET is_active = FALSE WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['message' => 'Регион успешно деактивирован']);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Метод не поддерживается']);
}

