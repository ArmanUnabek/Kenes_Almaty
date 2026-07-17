<?php
/**
 * GET /api/calendar_export.php
 * Экспорт мероприятий текущего региона в формате iCalendar (.ics).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

checkAuth();

$db = getDBConnection();
$regionId = resolveRegionIdForRead();

// Необязательный явный region_id: только регион, к которому есть доступ
// (админ — любой, 'all' у админа = все регионы; остальные — только свой).
$requestedRegion = $_GET['region_id'] ?? '';
if ($requestedRegion !== '') {
    $user = getCurrentUser();
    $isAdmin = \App\Auth\AccessPolicy::isAdmin($user['role'] ?? '');
    if ($requestedRegion === 'all') {
        if ($isAdmin) {
            $regionId = null;
        }
    } elseif ((int)$requestedRegion > 0 && canAccessRegion((int)$requestedRegion)) {
        $regionId = (int)$requestedRegion;
    }
}

/**
 * Валидация query-параметра диапазона дат (from/to): строго YYYY-MM-DD,
 * иначе игнорируется (полный экспорт, как раньше).
 */
function icsValidDateParam(?string $raw): ?string
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        return null;
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return null;
    }
    return $raw;
}

$from = icsValidDateParam($_GET['from'] ?? null);
$to = icsValidDateParam($_GET['to'] ?? null);
if ($from !== null && $to !== null) {
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    // Разумный максимум диапазона — 1 год
    if (strtotime($to) - strtotime($from) > 366 * 86400) {
        $to = date('Y-m-d', strtotime($from . ' +1 year'));
    }
}

$sql = 'SELECT id, title, event_date, location, location_url, description FROM events WHERE 1=1';
$params = [];
if ($regionId !== null) {
    $sql .= ' AND region_id = ?';
    $params[] = $regionId;
}
if ($from !== null) {
    $sql .= ' AND event_date >= ?';
    $params[] = $from;
}
if ($to !== null) {
    $sql .= ' AND event_date <= ?';
    $params[] = $to;
}
$sql .= ' ORDER BY event_date';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Экранирование текста по RFC 5545.
 */
function icsEscape(?string $s): string
{
    $s = (string)$s;
    $s = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $s);
    return str_replace(["\r\n", "\n", "\r"], '\\n', $s);
}

/**
 * Фолдинг длинных строк (max 75 октетов) по RFC 5545.
 */
function icsFold(string $line): string
{
    $out = [];
    while (strlen($line) > 73) {
        $chunk = substr($line, 0, 73);
        // не резать многобайтовый UTF-8 символ посередине
        while ($chunk !== '' && (ord(substr($line, strlen($chunk), 1)) & 0xC0) === 0x80) {
            $chunk = substr($chunk, 0, -1);
        }
        $out[] = $chunk;
        $line = ' ' . substr($line, strlen($chunk));
    }
    $out[] = $line;
    return implode("\r\n", $out);
}

$now = gmdate('Ymd\THis\Z');
$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Zhurnal OS//Calendar Export//RU',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
];

foreach ($events as $ev) {
    $date = substr((string)($ev['event_date'] ?? ''), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        continue;
    }
    $dt = str_replace('-', '', $date);
    $dtEnd = gmdate('Ymd', strtotime($date . ' +1 day'));
    $desc = trim((string)($ev['description'] ?? ''));
    if (!empty($ev['location_url'])) {
        $desc .= ($desc !== '' ? "\n" : '') . $ev['location_url'];
    }

    $lines[] = 'BEGIN:VEVENT';
    $lines[] = icsFold('UID:event-' . (int)$ev['id'] . '@zhurnal-os');
    $lines[] = 'DTSTAMP:' . $now;
    $lines[] = 'DTSTART;VALUE=DATE:' . $dt;
    $lines[] = 'DTEND;VALUE=DATE:' . $dtEnd;
    $lines[] = icsFold('SUMMARY:' . icsEscape($ev['title'] ?? ''));
    if (!empty($ev['location'])) {
        $lines[] = icsFold('LOCATION:' . icsEscape($ev['location']));
    }
    if ($desc !== '') {
        $lines[] = icsFold('DESCRIPTION:' . icsEscape($desc));
    }
    $lines[] = 'END:VEVENT';
}

$lines[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="events.ics"');
header('Cache-Control: no-store');
echo implode("\r\n", $lines) . "\r\n";
