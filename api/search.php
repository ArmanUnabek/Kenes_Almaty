<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

checkAuth();

$db = getDBConnection();
$q = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
$scope = $_GET['scope'] ?? 'all';

if ($q === '') {
    echo json_encode(['items' => []], JSON_ENCODE_FLAGS);
    exit;
}

$regionId = resolveRegionIdForRead();
$like = '%' . $q . '%';
$items = [];

$regionClauseIncoming = $regionId ? ' AND region_id = ?' : '';
$regionClauseOutgoing = $regionId ? ' AND region_id = ?' : '';
$regionClauseMembers = $regionId ? ' AND m.region_id = ?' : '';
$regionParams = $regionId ? [$regionId] : [];

// deleted_at filter: active by default, archived when scope=archived
$archivedFilter = $scope === 'archived'
    ? " AND (deleted_at IS NOT NULL AND deleted_at != '0000-00-00 00:00:00')"
    : " AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";

if ($scope === 'all' || $scope === 'letters' || $scope === 'archived') {
    $sqlIncoming = "
        SELECT 'incoming' AS source, id, date, organization, subject, kk_number AS number_label
        FROM incoming_letters
        WHERE (subject LIKE ? OR note LIKE ? OR organization LIKE ? OR kk_number LIKE ?)
        {$archivedFilter}
        {$regionClauseIncoming}
        ORDER BY date DESC
        LIMIT ?
    ";
    try {
        $stmt = $db->prepare($sqlIncoming);
        $params = [$like, $like, $like, $like];
        if ($regionId) {
            $params[] = $regionId;
        }
        $params[] = $limit;
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $row;
        }
    } catch (\Throwable $e) {
        // deleted_at column not yet created
        // For archived scope: no archived letters can exist yet, so return nothing
        if ($scope !== 'archived') {
            $sqlFallback = "
                SELECT 'incoming' AS source, id, date, organization, subject, kk_number AS number_label
                FROM incoming_letters
                WHERE (subject LIKE ? OR note LIKE ? OR organization LIKE ? OR kk_number LIKE ?)
                {$regionClauseIncoming}
                ORDER BY date DESC
                LIMIT ?
            ";
            $stmt = $db->prepare($sqlFallback);
            $params = [$like, $like, $like, $like];
            if ($regionId) {
                $params[] = $regionId;
            }
            $params[] = $limit;
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $items[] = $row;
            }
        }
    }

    $sqlOutgoing = "
        SELECT 'outgoing' AS source, id, date, organization, subject, outgoing_number AS number_label
        FROM outgoing_letters
        WHERE (subject LIKE ? OR note LIKE ? OR organization LIKE ? OR outgoing_number LIKE ?)
        {$archivedFilter}
        {$regionClauseOutgoing}
        ORDER BY date DESC
        LIMIT ?
    ";
    try {
        $stmt = $db->prepare($sqlOutgoing);
        $params = [$like, $like, $like, $like];
        if ($regionId) {
            $params[] = $regionId;
        }
        $params[] = $limit;
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $row;
        }
    } catch (\Throwable $e) {
        // deleted_at column not yet created
        if ($scope !== 'archived') {
            $sqlFallback = "
                SELECT 'outgoing' AS source, id, date, organization, subject, outgoing_number AS number_label
                FROM outgoing_letters
                WHERE (subject LIKE ? OR note LIKE ? OR organization LIKE ? OR outgoing_number LIKE ?)
                {$regionClauseOutgoing}
                ORDER BY date DESC
                LIMIT ?
            ";
            $stmt = $db->prepare($sqlFallback);
            $params = [$like, $like, $like, $like];
            if ($regionId) {
                $params[] = $regionId;
            }
            $params[] = $limit;
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $items[] = $row;
            }
        }
    }
}

if ($scope === 'all' || $scope === 'members') {
    $sqlMembers = "
        SELECT 'member' AS source, m.id, m.full_name AS subject, m.position AS organization,
               c.name AS number_label, '' AS date
        FROM os_members m
        LEFT JOIN commissions c ON m.commission_id = c.id
        WHERE m.status = 'active'
          AND (m.full_name LIKE ? OR m.position LIKE ? OR m.organization LIKE ?)
          {$regionClauseMembers}
        ORDER BY m.full_name ASC
        LIMIT ?
    ";
    $stmt = $db->prepare($sqlMembers);
    $params = [$like, $like, $like];
    if ($regionId) {
        $params[] = $regionId;
    }
    $params[] = $limit;
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = $row;
    }
}

$items = \App\Services\SearchRanker::sort($items);

echo json_encode([
    'items' => array_slice($items, 0, $limit),
    'query' => $q,
    'region_id' => $regionId,
], JSON_ENCODE_FLAGS);
