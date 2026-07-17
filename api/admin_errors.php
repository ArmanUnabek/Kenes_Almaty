<?php
/**
 * «Последние ошибки» — только для админа.
 *
 * Источник: приложение пишет ошибки двумя путями:
 *   1) App\Logger  → файлы logs/YYYY-MM-DD.log   (формат: [ts] [LEVEL] msg | ctx)
 *   2) App\ErrorHandler / PHP → error_log()      (ini_get('error_log'))
 *
 * Эндпоинт читает последние N строк из этих источников, парсит уровень/время/
 * сообщение, обрезает и обезличивает (без путей за пределами basename) и отдаёт
 * JSON. Только GET, без мутаций.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

header('Content-Type: application/json; charset=utf-8');

checkAuth();
requireRole(['admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не поддерживается'], JSON_ENCODE_FLAGS);
    exit;
}

$limit   = min(500, max(1, (int)($_GET['limit'] ?? 100)));
$maxLine = 1000; // максимальная длина одной строки сообщения

/**
 * Читает «хвост» файла (последние ~256 КБ), возвращает массив строк.
 *
 * @return string[]
 */
function tailLines(string $file, int $maxBytes = 262144): array
{
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }
    $size = filesize($file);
    if ($size === false || $size === 0) {
        return [];
    }
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return [];
    }
    if ($size > $maxBytes) {
        fseek($fh, -$maxBytes, SEEK_END);
        fgets($fh); // отбрасываем возможную неполную первую строку
    }
    $lines = [];
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    fclose($fh);
    return $lines;
}

/**
 * Разбирает строку лога в структуру {time, level, message}.
 *
 * @return array{time:?string,level:string,message:string}
 */
function parseLine(string $line, int $maxLen): array
{
    $time = null;
    $level = 'ERROR';
    $message = $line;

    // Формат App\Logger: [2026-07-10 12:00:00] [ERROR] message | ctx
    if (preg_match('/^\[([^\]]+)\]\s*\[([A-Za-z]+)\]\s*(.*)$/s', $line, $m)) {
        $time = $m[1];
        $level = strtoupper($m[2]);
        $message = $m[3];
    } elseif (preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $line, $m)) {
        // Формат PHP error_log: [10-Jul-2026 12:00:00 UTC] PHP Warning: ...
        $time = $m[1];
        $rest = $m[2];
        if (preg_match('/\b(Fatal error|Parse error|Warning|Notice|Deprecated|Error)\b/i', $rest, $lm)) {
            $level = strtoupper($lm[1]);
        }
        $message = $rest;
    }

    // Обезличивание: не раскрываем абсолютные пути на сервере — оставляем basename.
    $message = preg_replace_callback(
        '#(?:/[\w.\-]+)+/([\w.\-]+\.php)#',
        static fn($mm) => $mm[1],
        $message
    );
    $message = preg_replace_callback(
        '#[A-Za-z]:\\\\(?:[\w.\-]+\\\\)+([\w.\-]+\.php)#',
        static fn($mm) => $mm[1],
        $message
    );

    // Убираем управляющие символы и обрезаем.
    $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', (string)$message);
    if (mb_strlen($message) > $maxLen) {
        $message = mb_substr($message, 0, $maxLen) . '…';
    }

    return ['time' => $time, 'level' => $level, 'message' => $message];
}

$rawLines = [];

// Источник 1: собственные суточные логи приложения (несколько последних файлов).
$logDir = APP_ROOT . '/logs';
if (is_dir($logDir)) {
    $files = glob($logDir . '/*.log') ?: [];
    // Сортируем по времени изменения, берём 3 свежих файла.
    usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, 0, 3) as $f) {
        foreach (tailLines($f) as $l) {
            $rawLines[] = $l;
        }
    }
}

// Источник 2: PHP error_log (если задан и это отдельный файл).
$phpErrorLog = (string)ini_get('error_log');
if ($phpErrorLog !== '' && $phpErrorLog !== 'syslog' && is_file($phpErrorLog)) {
    foreach (tailLines($phpErrorLog) as $l) {
        $rawLines[] = $l;
    }
}

// Берём последние $limit строк, парсим, отдаём в обратном порядке (свежие сверху).
$rawLines = array_slice($rawLines, -$limit);
$items = [];
foreach ($rawLines as $line) {
    $items[] = parseLine($line, $maxLine);
}
$items = array_reverse($items);

$source = 'logs/*.log';
if (empty($items) && $phpErrorLog !== '' && is_file($phpErrorLog)) {
    $source = 'php_error_log';
}

echo json_encode([
    'items'  => $items,
    'count'  => count($items),
    'source' => $source,
], JSON_ENCODE_FLAGS);
