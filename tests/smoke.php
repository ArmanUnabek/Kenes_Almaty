<?php
/**
 * Smoke-тест проекта.
 *
 * Запуск: php tests/smoke.php [base_url]
 * По умолчанию base_url = http://localhost
 *
 * Секция 1 (offline): php -l по ключевым PHP-файлам проекта.
 * Секция 2 (HTTP): проверка ключевых endpoint'ов. Если сервер недоступен —
 * WARN и skip (не fail).
 *
 * Любой lint-fail, HTTP 500 или несоответствие ожиданиям -> exit(1).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$ROOT = dirname(__DIR__);
$baseUrl = rtrim($argv[1] ?? 'http://localhost', '/');

$useColor = (function_exists('stream_isatty') ? @stream_isatty(STDOUT) : false)
    || getenv('FORCE_COLOR');
function clr(string $s, string $code): string {
    global $useColor;
    return $useColor ? "\033[{$code}m{$s}\033[0m" : $s;
}
function ok(string $msg): void   { echo clr('[OK]   ', '32') . $msg . "\n"; }
function fail(string $msg): void { echo clr('[FAIL] ', '31') . $msg . "\n"; }
function warn(string $msg): void { echo clr('[WARN] ', '33') . $msg . "\n"; }

$failures = 0;

/* ---------------------------------------------------------------------------
 * Секция 1: php -l (lint) ключевых файлов
 * ------------------------------------------------------------------------- */

echo "== Lint (php -l) ==\n";

$phpBin = PHP_BINARY ?: 'php';

$lintFiles = [];
foreach (glob($ROOT . '/*.php') ?: [] as $f) {
    $lintFiles[] = $f;
}
foreach (glob($ROOT . '/api/*.php') ?: [] as $f) {
    $lintFiles[] = $f;
}
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($ROOT . '/src', FilesystemIterator::SKIP_DOTS)
);
foreach ($rii as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $lintFiles[] = $file->getPathname();
    }
}
// vendor исключён по построению (не входит в шаблоны выше)

/**
 * php -l через stdin — пути с не-ASCII символами (Windows) не ломаются.
 * @return array{int, string} [exit code, output]
 */
function lintFile(string $phpBin, string $file): array
{
    $proc = proc_open(
        [$phpBin, '-l'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        return [1, 'proc_open failed'];
    }
    fwrite($pipes[0], (string) file_get_contents($file));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), trim((string) $out)];
}

$lintErrors = 0;
foreach ($lintFiles as $file) {
    [$code, $out] = lintFile($phpBin, $file);
    if ($code !== 0) {
        $lintErrors++;
        fail('lint: ' . $file);
        echo '       ' . $out . "\n";
    }
}
if ($lintErrors === 0) {
    ok('lint: ' . count($lintFiles) . ' файлов без синтаксических ошибок');
} else {
    $failures += $lintErrors;
}

/* ---------------------------------------------------------------------------
 * Секция 2: HTTP endpoints
 * ------------------------------------------------------------------------- */

echo "\n== HTTP ({$baseUrl}) ==\n";

/**
 * @return array{status:int, body:string}|null null при сетевой ошибке
 */
function httpGet(string $url): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'smoke-test',
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            curl_close($ch);
            return null;
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $body];
    }

    // Fallback: file_get_contents
    $ctx = stream_context_create([
        'http' => ['timeout' => 10, 'ignore_errors' => true, 'user_agent' => 'smoke-test'],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    return ['status' => $status, 'body' => $body];
}

// Доступен ли сервер вообще?
$probe = httpGet($baseUrl . '/login.php');
if ($probe === null) {
    warn("Сервер {$baseUrl} недоступен — HTTP-проверки пропущены (skip).");
} else {
    /**
     * @var array<int, array{path:string, status:int, contains?:string, json?:bool}>
     */
    $checks = [
        ['path' => '/login.php',                        'status' => 200, 'contains' => 'Вход'],
        ['path' => '/api/auth.php?action=check',        'status' => 401, 'json' => true],
        ['path' => '/api/auth.php?action=csrf',         'status' => 200, 'contains' => 'csrf_token'],
        ['path' => '/api/letters.php',                  'status' => 401],
        ['path' => '/api/config_public.php',            'status' => 200],
        ['path' => '/appeal.php',                       'status' => 200],
        ['path' => '/api/sw.js',                        'status' => 200],
        ['path' => '/assets/vendor/bootstrap.min.css',  'status' => 200],
        ['path' => '/styles.css',                       'status' => 200],
    ];

    foreach ($checks as $check) {
        $url = $baseUrl . $check['path'];
        $res = httpGet($url);
        if ($res === null) {
            fail("{$check['path']} — сетевая ошибка");
            $failures++;
            continue;
        }
        $problems = [];
        if ($res['status'] >= 500) {
            $problems[] = "HTTP {$res['status']} (server error)";
        } elseif ($res['status'] !== $check['status']) {
            $problems[] = "HTTP {$res['status']}, ожидался {$check['status']}";
        }
        if (isset($check['contains']) && strpos($res['body'], $check['contains']) === false) {
            $problems[] = "нет подстроки \"{$check['contains']}\"";
        }
        if (!empty($check['json'])) {
            json_decode($res['body']);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $problems[] = 'ответ не JSON';
            }
        }
        if ($problems) {
            fail($check['path'] . ' — ' . implode('; ', $problems));
            $failures++;
        } else {
            ok($check['path'] . " — HTTP {$res['status']}");
        }
    }
}

/* ------------------------------------------------------------------------- */

echo "\n";
if ($failures > 0) {
    echo clr("SMOKE FAILED: {$failures} ошибок\n", '31;1');
    exit(1);
}
echo clr("SMOKE OK\n", '32;1');
exit(0);
