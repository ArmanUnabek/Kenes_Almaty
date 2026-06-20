<?php
/**
 * CSV batch import for incoming/outgoing letters.
 *
 * POST /api/import.php
 *   Content-Type: multipart/form-data
 *   file: <csv-file>
 *   type: incoming | outgoing  (default: incoming)
 *
 * CSV format (semicolon-separated, UTF-8 with BOM):
 *   Incoming:  date;organization;kk_number;category;subject;note
 *   Outgoing:  date;outgoing_number;organization;subject;note;outgoing_type
 *
 * Returns JSON: { imported, skipped, errors: [{row, message}] }
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimiter;
use App\Validator;
use App\Services\AuditLogger;
use App\Services\LetterService;

header('Content-Type: application/json; charset=utf-8');

checkAuth();
requireRole(['admin', 'moderator']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_ENCODE_FLAGS);
    exit;
}

CsrfMiddleware::requireVerification();

$user     = getCurrentUser();
$userId   = (int)($user['id'] ?? 0);
$regionId = getCurrentRegionId();

RateLimiter::requireCheck('import_csv_' . $userId, 5, 3600);

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $uploadErr = $_FILES['file']['error'] ?? -1;
    echo json_encode(['error' => 'Файл не загружен или ошибка загрузки (код: ' . $uploadErr . ')'], JSON_ENCODE_FLAGS);
    exit;
}

$tmpPath  = $_FILES['file']['tmp_name'];
$origName = $_FILES['file']['name'] ?? 'import.csv';
$mimeType = mime_content_type($tmpPath);

// Accept CSV and plain text
$allowedMimes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
if (!in_array($mimeType, $allowedMimes, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Файл должен быть в формате CSV (определён тип: ' . $mimeType . ')'], JSON_ENCODE_FLAGS);
    exit;
}

$maxBytes = 5 * 1024 * 1024; // 5 MB
if ($_FILES['file']['size'] > $maxBytes) {
    http_response_code(422);
    echo json_encode(['error' => 'Файл слишком большой (макс. 5 МБ)'], JSON_ENCODE_FLAGS);
    exit;
}

$type = strtolower((string)($_POST['type'] ?? 'incoming'));
if (!in_array($type, ['incoming', 'outgoing'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'type должен быть incoming или outgoing'], JSON_ENCODE_FLAGS);
    exit;
}

// Read file, strip BOM if present
$content = file_get_contents($tmpPath);
if (str_starts_with($content, "\xEF\xBB\xBF")) {
    $content = substr($content, 3);
}
// Normalize line endings
$content = str_replace("\r\n", "\n", str_replace("\r", "\n", $content));
$lines   = explode("\n", trim($content));

if (count($lines) < 2) {
    http_response_code(422);
    echo json_encode(['error' => 'CSV файл пуст или содержит только заголовок'], JSON_ENCODE_FLAGS);
    exit;
}

// Skip header row
array_shift($lines);

$db        = getDBConnection();
$validator = new Validator();
$imported  = 0;
$skipped   = 0;
$errors    = [];
$rowNum    = 1; // header was row 0

foreach ($lines as $line) {
    $rowNum++;
    $line = trim($line);
    if ($line === '') {
        $skipped++;
        continue;
    }

    // Parse semicolon-separated CSV (handle quoted fields)
    $fields = str_getcsv($line, ';', '"');

    try {
        if ($type === 'incoming') {
            // date;organization;kk_number;category;subject;note
            $date         = trim($fields[0] ?? '');
            $organization = trim($fields[1] ?? '');
            $kkNumber     = trim($fields[2] ?? '');
            $category     = strtoupper(trim($fields[3] ?? 'KK'));
            $subject      = trim($fields[4] ?? '');
            $note         = trim($fields[5] ?? '');

            $valid = $validator->validate(
                ['date' => $date, 'organization' => $organization, 'subject' => $subject],
                [
                    'date'         => 'required|date',
                    'organization' => 'required|string|min:1|max:255',
                    'subject'      => 'string|max:1000',
                ]
            );
            if (!$valid) {
                $errors[] = ['row' => $rowNum, 'message' => $validator->getFirstError()];
                $skipped++;
                continue;
            }

            if (!in_array($category, ['KK', 'N', 'JT', 'ZT'], true)) {
                $category = 'KK';
            }

            $seq = LetterService::computeNextSeq($db, 'incoming_letters', $regionId);

            $stmt = $db->prepare("
                INSERT INTO incoming_letters
                    (region_id, seq, date, organization, kk_number, category, subject, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $regionId,
                $seq,
                $date,
                $organization,
                $kkNumber,
                $category,
                $subject,
                $note,
                $userId,
            ]);
            $letterId = (int)$db->lastInsertId();

            AuditLogger::log($db, 'incoming_letters', $letterId, 'IMPORT_CSV', null, [
                'seq' => $seq, 'organization' => $organization,
            ], $userId);

        } else {
            // date;outgoing_number;organization;subject;note;outgoing_type
            $date           = trim($fields[0] ?? '');
            $outgoingNumber = trim($fields[1] ?? '');
            $organization   = trim($fields[2] ?? '');
            $subject        = trim($fields[3] ?? '');
            $note           = trim($fields[4] ?? '');
            $outgoingType   = LetterService::normalizeOutgoingType(trim($fields[5] ?? ''));

            $valid = $validator->validate(
                ['date' => $date, 'organization' => $organization],
                [
                    'date'         => 'required|date',
                    'organization' => 'required|string|min:1|max:255',
                ]
            );
            if (!$valid) {
                $errors[] = ['row' => $rowNum, 'message' => $validator->getFirstError()];
                $skipped++;
                continue;
            }

            $seq = LetterService::computeNextSeq($db, 'outgoing_letters', $regionId);

            $stmt = $db->prepare("
                INSERT INTO outgoing_letters
                    (region_id, seq, date, outgoing_number, organization, subject, note, outgoing_type, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $regionId,
                $seq,
                $date,
                $outgoingNumber !== '' ? $outgoingNumber : null,
                $organization,
                $subject,
                $note,
                $outgoingType,
                $userId,
            ]);
            $letterId = (int)$db->lastInsertId();

            AuditLogger::log($db, 'outgoing_letters', $letterId, 'IMPORT_CSV', null, [
                'seq' => $seq, 'organization' => $organization,
            ], $userId);
        }

        $imported++;

    } catch (\Throwable $e) {
        $errors[] = ['row' => $rowNum, 'message' => 'Ошибка вставки: ' . $e->getMessage()];
        $skipped++;
        error_log('import.php row ' . $rowNum . ': ' . $e->getMessage());
    }
}

echo json_encode([
    'imported' => $imported,
    'skipped'  => $skipped,
    'errors'   => array_slice($errors, 0, 50),
], JSON_ENCODE_FLAGS);
