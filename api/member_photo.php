<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Middleware\RateLimiter;
use App\Repositories\MemberRepository;

checkAuth();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$memberId = (int)($_GET['member_id'] ?? 0);
if ($memberId <= 0) {
    http_response_code(400);
    exit;
}

RateLimiter::requireCheck('member_photo_' . $userId, 300, 3600);

$db = getDBConnection();
$repo = new MemberRepository($db);
$member = $repo->getById($memberId);
if (!$member || empty($member['photo_path'])) {
    http_response_code(404);
    exit;
}

$regionId = (int)($member['region_id'] ?? 0);
if ($regionId <= 0 || !canAccessRegion($regionId)) {
    http_response_code(404);
    exit;
}

$uploadsRoot = realpath(APP_ROOT . '/uploads/photos');
$fullPath = realpath(APP_ROOT . '/' . ltrim((string)$member['photo_path'], '/'));
if (!$uploadsRoot || !$fullPath || !str_starts_with($fullPath, $uploadsRoot . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit;
}

$mimeMap = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
];
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$contentType = $mimeMap[$ext] ?? 'application/octet-stream';
if (!isset($mimeMap[$ext])) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
