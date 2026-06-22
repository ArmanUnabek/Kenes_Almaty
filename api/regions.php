<?php
require_once '../config.php';
require_once '../auth_middleware.php';

use App\Middleware\CsrfMiddleware;
use App\Services\RegionService;

header('Content-Type: application/json; charset=utf-8');

$JSON_FLAGS = JSON_ENCODE_FLAGS;

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$db = getDBConnection();

// Доступ к регионам только для авторизованных пользователей
checkAuth();

// Мутации только для админов и под защитой CSRF
if ($method !== 'GET') {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещен'], $JSON_FLAGS);
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
                echo json_encode($region, $JSON_FLAGS);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Регион не найден'], $JSON_FLAGS);
            }
        } elseif ($code) {
            $stmt = $db->prepare("SELECT * FROM regions WHERE code = ?");
            $stmt->execute([$code]);
            $region = $stmt->fetch();
            echo json_encode($region ?: null, $JSON_FLAGS);
        } else {
            $sql = "SELECT * FROM regions";
            if ($activeOnly) {
                $sql .= " WHERE is_active = TRUE";
            }
            $sql .= " ORDER BY name_ru";
            
            $stmt = $db->query($sql);
            $regions = $stmt->fetchAll();
            echo json_encode($regions, $JSON_FLAGS);
        }
        break;
        
    case 'POST':
        if ($action === 'bootstrap') {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $regionId = (int)($data['region_id'] ?? 0);
            if ($regionId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'region_id обязателен'], $JSON_FLAGS);
                break;
            }
            try {
                $result = RegionService::bootstrap(
                    $db,
                    $regionId,
                    isset($data['template_region_id']) ? (int)$data['template_region_id'] : 1,
                    [
                        'seq_baseline_incoming' => (int)($data['seq_baseline_incoming'] ?? 0),
                        'seq_baseline_outgoing' => (int)($data['seq_baseline_outgoing'] ?? 0),
                        'copy_commissions' => $data['copy_commissions'] ?? true,
                    ]
                );
                echo json_encode([
                    'message' => 'Регион инициализирован',
                    'commissions_copied' => $result['commissions_copied'],
                    'settings' => $result['settings'],
                ], $JSON_FLAGS);
            } catch (\Throwable $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()], $JSON_FLAGS);
            }
            break;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $defaultSettings = [
            'seq_baseline_incoming' => (int)($data['settings']['seq_baseline_incoming'] ?? 0),
            'seq_baseline_outgoing' => (int)($data['settings']['seq_baseline_outgoing'] ?? 0),
        ];
        $stmt = $db->prepare("
            INSERT INTO regions (name_kz, name_ru, code, is_active, settings)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['name_kz'] ?? '',
            $data['name_ru'] ?? '',
            $data['code'] ?? '',
            $data['is_active'] ?? TRUE,
            json_encode($data['settings'] ?? $defaultSettings)
        ]);
        
        $id = $db->lastInsertId();
        echo json_encode(['id' => $id, 'message' => 'Регион успешно создан'], $JSON_FLAGS);
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID не указан'], $JSON_FLAGS);
            break;
        }

        $existingStmt = $db->prepare('SELECT name_kz, name_ru, code, is_active, settings FROM regions WHERE id = ?');
        $existingStmt->execute([$id]);
        $existingRow = $existingStmt->fetch();
        if (!$existingRow) {
            http_response_code(404);
            echo json_encode(['error' => 'Регион не найден'], $JSON_FLAGS);
            break;
        }
        $existingSettings = RegionService::parseSettings($existingRow['settings'] ?? null);
        $newSettings = array_key_exists('settings', $data)
            ? array_merge($existingSettings, is_array($data['settings']) ? $data['settings'] : [])
            : $existingSettings;

        // Частичное обновление: отсутствующие поля сохраняют текущее значение,
        // а не затираются пустой строкой.
        $stmt = $db->prepare("
            UPDATE regions
            SET name_kz = ?, name_ru = ?, code = ?, is_active = ?, settings = ?
            WHERE id = ?
        ");

        $stmt->execute([
            array_key_exists('name_kz', $data) ? $data['name_kz'] : $existingRow['name_kz'],
            array_key_exists('name_ru', $data) ? $data['name_ru'] : $existingRow['name_ru'],
            array_key_exists('code', $data) ? $data['code'] : $existingRow['code'],
            array_key_exists('is_active', $data) ? ($data['is_active'] ? 1 : 0) : $existingRow['is_active'],
            json_encode($newSettings, JSON_ENCODE_FLAGS),
            $id
        ]);
        
        echo json_encode(['message' => 'Регион успешно обновлен'], $JSON_FLAGS);
        break;
        
    case 'DELETE':
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID не указан'], $JSON_FLAGS);
            break;
        }
        
        $stmt = $db->prepare("UPDATE regions SET is_active = FALSE WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['message' => 'Регион успешно деактивирован'], $JSON_FLAGS);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Метод не поддерживается'], $JSON_FLAGS);
}

