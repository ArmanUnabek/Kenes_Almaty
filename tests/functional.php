<?php
/**
 * Функциональный smoke-тест сквозного цикла письма («Журнал ОС»).
 *
 * Запуск:
 *   php tests/functional.php [base_url] [username] [password]
 *
 * Креды берутся (по приоритету): аргументы CLI -> env TEST_USER / TEST_PASS.
 * Если креды не заданы или сервер недоступен — тест SKIP'ается (exit 0),
 * НЕ падает.
 *
 * Сценарий (сессия на общем cookie-jar через curl):
 *   1. GET ?action=csrf                — получить CSRF-токен + завести cookie-сессию
 *   2. POST ?action=login             — вход тестовыми кредами (form-urlencoded)
 *   3. POST letters (incoming)        — создать письмо с уникальным kk_number
 *   4. GET  letters (incoming)        — убедиться, что письмо появилось в списке
 *   5. DELETE letters?id=…            — архивировать (soft delete) — cleanup
 *   6. GET  letters (incoming)        — убедиться, что письмо ушло из активного списка
 *
 * CSRF-токен ротируется после каждой мутации: берём свежий из заголовка
 * ответа X-New-CSRF-Token, а перед каждой мутацией дополнительно
 * перезапрашиваем ?action=csrf для надёжности.
 *
 * Любой FAIL после успешного логина -> exit(1). SKIP -> exit(0).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "Требуется расширение curl\n");
    exit(1);
}

$baseUrl  = rtrim($argv[1] ?? getenv('TEST_BASE_URL') ?: 'http://localhost', '/');
$username = $argv[2] ?? (getenv('TEST_USER') ?: '');
$password = $argv[3] ?? (getenv('TEST_PASS') ?: '');

$useColor = (function_exists('stream_isatty') ? @stream_isatty(STDOUT) : false)
    || getenv('FORCE_COLOR');
function clr(string $s, string $code): string {
    global $useColor;
    return $useColor ? "\033[{$code}m{$s}\033[0m" : $s;
}
function ok(string $msg): void   { echo clr('[OK]   ', '32') . $msg . "\n"; }
function fail(string $msg): void { echo clr('[FAIL] ', '31') . $msg . "\n"; }
function warn(string $msg): void { echo clr('[WARN] ', '33') . $msg . "\n"; }
function skip(string $msg): void { echo clr('[SKIP] ', '36') . $msg . "\n"; }

$cookieJar = tempnam(sys_get_temp_dir(), 'func_test_cookies_');
register_shutdown_function(static function () use ($cookieJar) {
    if ($cookieJar && is_file($cookieJar)) {
        @unlink($cookieJar);
    }
});

/**
 * HTTP-запрос через curl с общим cookie-jar (сессия сохраняется между вызовами).
 *
 * @param array<string,string> $headers
 * @return array{status:int, body:string, headers:array<string,string>}|null
 *         null — сетевая ошибка (сервер недоступен)
 */
function httpRequest(
    string $method,
    string $url,
    ?string $body = null,
    array $headers = []
): ?array {
    global $cookieJar;

    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'functional-test',
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $resBody = curl_exec($ch);
    if ($resBody === false) {
        curl_close($ch);
        return null;
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $resBody, 'headers' => $respHeaders];
}

/**
 * Свежий CSRF-токен. Сначала пробуем из заголовка предыдущей мутации,
 * иначе (или всегда для надёжности) перезапрашиваем ?action=csrf.
 */
function fetchCsrf(string $baseUrl): ?string
{
    $res = httpRequest('GET', $baseUrl . '/api/auth.php?action=csrf');
    if ($res === null || $res['status'] !== 200) {
        return null;
    }
    $data = json_decode($res['body'], true);
    return is_array($data) ? ($data['csrf_token'] ?? null) : null;
}

echo "== Функциональный тест ({$baseUrl}) ==\n";

/* --- Предусловие: доступен ли сервер? --------------------------------- */
$probe = httpRequest('GET', $baseUrl . '/api/auth.php?action=csrf');
if ($probe === null) {
    skip("Сервер {$baseUrl} недоступен — тест пропущен.");
    exit(0);
}

/* --- Предусловие: заданы ли креды? ------------------------------------ */
if ($username === '' || $password === '') {
    skip('Тестовые креды не заданы (TEST_USER/TEST_PASS или аргументы) — тест пропущен.');
    warn('Запуск с реальными кредами: php tests/functional.php ' . $baseUrl . ' <user> <pass>');
    exit(0);
}

$failures = 0;

/* --- Шаг 1: CSRF + cookie-сессия -------------------------------------- */
$token = fetchCsrf($baseUrl);
if ($token === null || $token === '') {
    fail('Шаг 1: не удалось получить CSRF-токен');
    echo clr("\nFUNCTIONAL FAILED\n", '31;1');
    exit(1);
}
ok('Шаг 1: CSRF-токен получен, cookie-сессия заведена');

/* --- Шаг 2: логин ----------------------------------------------------- */
$loginRes = httpRequest(
    'POST',
    $baseUrl . '/api/auth.php?action=login',
    http_build_query(['username' => $username, 'password' => $password]),
    [
        'Content-Type: application/x-www-form-urlencoded',
        'X-CSRF-Token: ' . $token,
    ]
);
if ($loginRes === null) {
    fail('Шаг 2: сетевая ошибка при логине');
    exit(1);
}
if (!empty($loginRes['headers']['x-new-csrf-token'])) {
    $token = $loginRes['headers']['x-new-csrf-token'];
}
$loginData = json_decode($loginRes['body'], true);
if ($loginRes['status'] === 202 && !empty($loginData['totp_required'])) {
    skip('Шаг 2: у аккаунта включена 2FA (TOTP) — сквозной тест невозможен без кода. Пропуск.');
    exit(0);
}
if ($loginRes['status'] !== 200 || empty($loginData['authenticated'])) {
    fail('Шаг 2: логин не удался — HTTP ' . $loginRes['status']
        . ' ' . ($loginData['error'] ?? substr($loginRes['body'], 0, 120)));
    echo clr("\nFUNCTIONAL FAILED\n", '31;1');
    exit(1);
}
ok('Шаг 2: вход выполнен (' . $username . ')');

/* --- Шаг 3: создать входящее письмо ----------------------------------- */
$kkNumber = 'SMOKE-' . time() . '-' . random_int(1000, 9999);
$token    = fetchCsrf($baseUrl) ?? $token; // свежий токен перед мутацией
$createRes = httpRequest(
    'POST',
    $baseUrl . '/api/letters.php?type=incoming',
    json_encode([
        'kk_number'    => $kkNumber,
        'organization' => 'SMOKE Test Org',
        'subject'      => 'Функциональный smoke-тест',
        'date'         => date('Y-m-d'),
        'category'     => 'KK',
    ], JSON_UNESCAPED_UNICODE),
    [
        'Content-Type: application/json',
        'X-CSRF-Token: ' . $token,
    ]
);
if ($createRes === null) {
    fail('Шаг 3: сетевая ошибка при создании письма');
    exit(1);
}
if (!empty($createRes['headers']['x-new-csrf-token'])) {
    $token = $createRes['headers']['x-new-csrf-token'];
}
$createData = json_decode($createRes['body'], true);
$letterId   = is_array($createData) ? ($createData['id'] ?? null) : null;
if ($createRes['status'] === 403) {
    skip('Шаг 3: нет прав на запись (write access) у этого аккаунта — пропуск. HTTP 403');
    exit(0);
}
if ($createRes['status'] !== 201 || !$letterId) {
    fail('Шаг 3: письмо не создано — HTTP ' . $createRes['status']
        . ' ' . ($createData['error'] ?? substr($createRes['body'], 0, 160)));
    $failures++;
} else {
    ok("Шаг 3: письмо создано (id={$letterId}, kk_number={$kkNumber})");
}

/* --- Шаг 4: письмо появилось в активном списке ------------------------ */
$found = false;
if ($letterId) {
    $listRes = httpRequest('GET', $baseUrl . '/api/letters.php?type=incoming&limit=200');
    if ($listRes === null || $listRes['status'] !== 200) {
        fail('Шаг 4: не удалось получить список — HTTP ' . ($listRes['status'] ?? 'net error'));
        $failures++;
    } else {
        $list = json_decode($listRes['body'], true);
        foreach (is_array($list) ? $list : [] as $row) {
            if ((string)($row['id'] ?? '') === (string)$letterId
                || ($row['kk_number'] ?? '') === $kkNumber) {
                $found = true;
                break;
            }
        }
        if ($found) {
            ok('Шаг 4: письмо найдено в активном списке');
        } else {
            fail('Шаг 4: созданное письмо НЕ найдено в активном списке');
            $failures++;
        }
    }
}

/* --- Шаг 5: архивация (soft delete) — cleanup ------------------------- */
$deleted = false;
if ($letterId) {
    $token = fetchCsrf($baseUrl) ?? $token;
    $delRes = httpRequest(
        'DELETE',
        $baseUrl . '/api/letters.php?type=incoming&id=' . $letterId,
        null,
        ['X-CSRF-Token: ' . $token]
    );
    if ($delRes === null) {
        fail('Шаг 5: сетевая ошибка при архивации');
        $failures++;
    } elseif (!empty($delRes['headers']['x-new-csrf-token'])) {
        $token = $delRes['headers']['x-new-csrf-token'];
    }
    if ($delRes !== null && $delRes['status'] === 403) {
        warn('Шаг 5: нет прав на удаление (delete access) — письмо id=' . $letterId
            . ' осталось активным (cleanup вручную).');
    } elseif ($delRes !== null && $delRes['status'] === 200) {
        $deleted = true;
        ok('Шаг 5: письмо перемещено в архив (soft delete)');
    } elseif ($delRes !== null) {
        fail('Шаг 5: архивация не удалась — HTTP ' . $delRes['status']
            . ' ' . substr($delRes['body'], 0, 160));
        $failures++;
    }
}

/* --- Шаг 6: письмо ушло из активного списка --------------------------- */
if ($deleted) {
    $listRes = httpRequest('GET', $baseUrl . '/api/letters.php?type=incoming&limit=200');
    if ($listRes === null || $listRes['status'] !== 200) {
        fail('Шаг 6: не удалось получить список — HTTP ' . ($listRes['status'] ?? 'net error'));
        $failures++;
    } else {
        $list = json_decode($listRes['body'], true);
        $stillThere = false;
        foreach (is_array($list) ? $list : [] as $row) {
            if ((string)($row['id'] ?? '') === (string)$letterId) {
                $stillThere = true;
                break;
            }
        }
        if ($stillThere) {
            fail('Шаг 6: письмо всё ещё в активном списке после архивации');
            $failures++;
        } else {
            ok('Шаг 6: письмо отсутствует в активном списке (архивировано)');
        }
    }
}

/* --------------------------------------------------------------------- */
echo "\n";
if ($failures > 0) {
    echo clr("FUNCTIONAL FAILED: {$failures} ошибок\n", '31;1');
    exit(1);
}
echo clr("FUNCTIONAL OK\n", '32;1');
exit(0);
