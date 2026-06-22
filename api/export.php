<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Middleware\RateLimiter;
use App\Services\SecurityAuditService;

checkAuth();
requireRole(['admin']);

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$format = strtolower((string)($_GET['format'] ?? 'json'));
if (!in_array($format, ['json', 'csv', 'xlsx'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Формат должен быть json, csv или xlsx'], JSON_ENCODE_FLAGS);
    exit;
}

RateLimiter::requireCheck(
    'export_user_' . $userId,
    SecurityAuditService::EXPORT_RATE_LIMIT,
    SecurityAuditService::EXPORT_RATE_WINDOW
);

$db = getDBConnection();
$regionId = getCurrentRegionId();
$regionClause = $regionId ? ' WHERE region_id = ?' : '';
$params = $regionId ? [$regionId] : [];

$stmt = $db->prepare('SELECT * FROM incoming_letters' . $regionClause . ' ORDER BY date DESC, seq DESC');
$stmt->execute($params);
$incoming = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM outgoing_letters' . $regionClause . ' ORDER BY date DESC, seq DESC');
$stmt->execute($params);
$outgoing = $stmt->fetchAll();

SecurityAuditService::logExport(
    $db,
    $userId,
    $regionId,
    $format,
    count($incoming),
    count($outgoing)
);

$date = date('Y-m-d');
$filename = 'os_journal_' . $date;

if ($format === 'json') {
    $payload = [
        'exported_at' => date('c'),
        'exported_by' => $user['username'] ?? null,
        'region_id' => $regionId,
        'incoming' => $incoming,
        'outgoing' => $outgoing,
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
    echo json_encode($payload, JSON_ENCODE_FLAGS | JSON_PRETTY_PRINT);
    exit;
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Тип', 'РегНомер', 'Дата', 'Организация', 'Категория', 'Номер', 'Тема', 'Примечание'], ';');
    foreach ($incoming as $row) {
        fputcsv($out, [
            'Входящее',
            'Вх.' . ($row['seq'] ?? ''),
            $row['date'] ?? '',
            $row['organization'] ?? '',
            $row['category'] ?? 'KK',
            $row['kk_number'] ?? '',
            $row['subject'] ?? '',
            $row['note'] ?? '',
        ], ';');
    }

    fputcsv($out, [], ';');
    fputcsv($out, ['Тип', 'Порядк№', 'Дата', 'Исходящий№', 'Организация', 'Тема', 'Примечание'], ';');
    foreach ($outgoing as $row) {
        fputcsv($out, [
            'Исходящее',
            'Исх.' . ($row['seq'] ?? ''),
            $row['date'] ?? '',
            $row['outgoing_number'] ?? '',
            $row['organization'] ?? '',
            $row['subject'] ?? '',
            $row['note'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

// ── XLSX export (SpreadsheetML / OOXML) ───────────────────────────────────────
if ($format === 'xlsx') {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    echo buildXlsx($incoming, $outgoing);
    exit;
}

function xlsEscape(mixed $v): string {
    if ($v === null || $v === '') return '';
    return htmlspecialchars((string)$v, ENT_XML1, 'UTF-8');
}

function xlsCell(mixed $v, string $type = 'inlineStr'): string {
    $safe = xlsEscape($v);
    if ($type === 'n') {
        return "<c t=\"n\"><v>{$safe}</v></c>";
    }
    return "<c t=\"inlineStr\"><is><t>{$safe}</t></is></c>";
}

function xlsRow(array $cells): string {
    $out = '<row>';
    foreach ($cells as $c) $out .= $c;
    $out .= '</row>';
    return $out;
}

function xlsHeaderRow(array $labels): string {
    $out = '<row>';
    foreach ($labels as $label) {
        $safe = xlsEscape($label);
        $out .= "<c t=\"inlineStr\"><is><t>{$safe}</t></is></c>";
    }
    $out .= '</row>';
    return $out;
}

function buildXlsx(array $incoming, array $outgoing): string {
    // Sheet 1: incoming
    $inRows = xlsHeaderRow(['Рег. №', 'Дата', 'Организация', 'Категория', 'Номер (ҚК)', 'Тема', 'Примечание']);
    foreach ($incoming as $r) {
        $inRows .= xlsRow([
            xlsCell('Вх.' . ($r['seq'] ?? '')),
            xlsCell($r['date'] ?? ''),
            xlsCell($r['organization'] ?? ''),
            xlsCell($r['category'] ?? 'KK'),
            xlsCell($r['kk_number'] ?? ''),
            xlsCell($r['subject'] ?? ''),
            xlsCell($r['note'] ?? ''),
        ]);
    }

    // Sheet 2: outgoing
    $outRows = xlsHeaderRow(['Порядк. №', 'Дата', 'Исходящий №', 'Организация', 'Тема', 'Примечание', 'Тип']);
    foreach ($outgoing as $r) {
        $outRows .= xlsRow([
            xlsCell('Исх.' . ($r['seq'] ?? '')),
            xlsCell($r['date'] ?? ''),
            xlsCell($r['outgoing_number'] ?? ''),
            xlsCell($r['organization'] ?? ''),
            xlsCell($r['subject'] ?? ''),
            xlsCell($r['note'] ?? ''),
            xlsCell($r['outgoing_type'] ?? 'gov'),
        ]);
    }

    $sheet1Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $inRows . '</sheetData></worksheet>';

    $sheet2Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $outRows . '</sheetData></worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>'
        . '<sheet name="Входящие" sheetId="1" r:id="rId1"/>'
        . '<sheet name="Исходящие" sheetId="2" r:id="rId2"/>'
        . '</sheets></workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $relsRoot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    // Build ZIP in a temp file (ZipArchive requires a real path, not php://temp)
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
    if ($tmp === false) {
        throw new \RuntimeException('Cannot create temp file for XLSX export');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new \RuntimeException('Cannot open ZipArchive for XLSX export');
    }
    try {
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $relsRoot);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);
        $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);
        $zip->close();
        $content = file_get_contents($tmp);
        if ($content === false) {
            throw new \RuntimeException('Cannot read XLSX temp file');
        }
        return $content;
    } finally {
        @unlink($tmp);
    }
}
