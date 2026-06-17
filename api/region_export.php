<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Services\RegionService;

checkAuth();
requireRole(['admin']);

$regionId = (int)($_GET['region_id'] ?? getCurrentRegionId() ?? 0);
if ($regionId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'region_id обязателен'], JSON_ENCODE_FLAGS);
    exit;
}

$db = getDBConnection();
$stmt = $db->prepare('SELECT * FROM regions WHERE id = ?');
$stmt->execute([$regionId]);
$region = $stmt->fetch();
if (!$region) {
    http_response_code(404);
    echo json_encode(['error' => 'Регион не найден'], JSON_ENCODE_FLAGS);
    exit;
}

$region['settings'] = RegionService::parseSettings($region['settings'] ?? null);

$members = $db->prepare('SELECT id, full_name, position, organization, commission_id, phone, email, status FROM os_members WHERE region_id = ?');
$members->execute([$regionId]);

$commissions = $db->prepare('SELECT id, name, description, color, sort_order FROM commissions WHERE region_id = ? ORDER BY sort_order, name');
$commissions->execute([$regionId]);

$incoming = $db->prepare('SELECT id, seq, date, organization, kk_number, category, subject, note FROM incoming_letters WHERE region_id = ? ORDER BY date DESC LIMIT 5000');
$incoming->execute([$regionId]);

$outgoing = $db->prepare('SELECT id, seq, date, outgoing_number, organization, subject, note FROM outgoing_letters WHERE region_id = ? ORDER BY date DESC LIMIT 5000');
$outgoing->execute([$regionId]);

$payload = [
    'exported_at' => date('c'),
    'region' => [
        'id' => (int)$region['id'],
        'name_ru' => $region['name_ru'],
        'name_kz' => $region['name_kz'],
        'code' => $region['code'],
        'settings' => $region['settings'],
    ],
    'commissions' => $commissions->fetchAll(),
    'members' => $members->fetchAll(),
    'incoming_letters' => $incoming->fetchAll(),
    'outgoing_letters' => $outgoing->fetchAll(),
];

$download = isset($_GET['download']) && $_GET['download'] === '1';
if ($download) {
    $filename = 'region_' . preg_replace('/[^a-z0-9_-]+/i', '_', (string)$region['code']) . '_' . date('Y-m-d') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($payload, JSON_ENCODE_FLAGS | JSON_PRETTY_PRINT);
    exit;
}

echo json_encode($payload, JSON_ENCODE_FLAGS);
