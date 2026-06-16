<?php
/**
 * Точка входа для веб-приложения.
 * В проде просто отдаём SPA/панель, при отсутствии БД — ведём на мастер установки.
 */

require_once __DIR__ . '/config.php';

/**
 * Удобная функция для безопасного редиректа.
 */
function safeRedirect(string $target): void
{
    if (!headers_sent()) {
        header('Location: ' . $target);
    } else {
        echo '<script>window.location.href="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    }
    exit;
}

/**
 * Проверяет, что схема БД развёрнута (есть таблица regions).
 * rowCount() для SELECT в SQLite всегда 0 — используем fetchColumn().
 */
function isDatabaseInstalled(PDO $db): bool
{
    if (DB_DRIVER === 'sqlite') {
        $stmt = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='regions' LIMIT 1");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    }
    if (DB_DRIVER === 'pgsql') {
        $stmt = $db->query("SELECT to_regclass('public.regions')");
        return $stmt !== false && $stmt->fetchColumn() !== null;
    }
    $stmt = $db->query("SHOW TABLES LIKE 'regions'");
    return $stmt !== false && $stmt->rowCount() > 0;
}

try {
    $db = getDBConnection();
    $isInstalled = isDatabaseInstalled($db);

    if ($isInstalled) {
        // В проекте вся логика авторизации/SPA в статичных файлах.
        $entry = file_exists(__DIR__ . '/login.html') ? 'login.html' : 'index.html';
        safeRedirect($entry);
    } else {
        // Database not installed - show error instead of redirecting to non-existent file
        http_response_code(500);
        $message = 'База данных не найдена. Создайте файл database.sqlite или импортируйте схему базы данных.';
        if (php_sapi_name() !== 'cli') {
            ?>
            <!DOCTYPE html>
            <html lang="ru">
            <head>
                <meta charset="UTF-8">
                <title>База данных не найдена</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 40px; background: #f8f9fa; color: #212529; }
                    .card { max-width: 560px; margin: auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,.08); }
                    h1 { font-size: 24px; margin-top: 0; }
                    .hint { margin-top: 16px; font-size: 14px; color: #6c757d; }
                    code { background: #f1f3f5; padding: 2px 6px; border-radius: 4px; }
                </style>
            </head>
            <body>
            <div class="card">
                <h1>База данных не найдена</h1>
                <p><?= htmlspecialchars($message, ENT_NOQUOTES, 'UTF-8'); ?></p>
                <p class="hint">
                    Для установки базы данных выполните:<br>
                    <code>sqlite3 database.sqlite < database_sqlite.sql</code><br>
                    или скопируйте файл database.sqlite из резервной копии.
                </p>
            </div>
            </body>
            </html>
            <?php
        } else {
            fwrite(STDERR, $message . PHP_EOL);
        }
        exit;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    $message = 'Не удалось подключиться к базе данных. Проверьте config.php.';
    if (php_sapi_name() !== 'cli') {
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <title>Ошибка подключения</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 40px; background: #f8f9fa; color: #212529; }
                .card { max-width: 560px; margin: auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,.08); }
                h1 { font-size: 24px; margin-top: 0; }
                .hint { margin-top: 16px; font-size: 14px; color: #6c757d; }
                code { background: #f1f3f5; padding: 2px 6px; border-radius: 4px; }
                .trace { margin-top: 16px; font-size: 13px; color: #adb5bd; white-space: pre-wrap; word-break: break-all; }
            </style>
        </head>
        <body>
        <div class="card">
            <h1>Ошибка подключения к БД</h1>
            <p><?= htmlspecialchars($message, ENT_NOQUOTES, 'UTF-8'); ?></p>
            <p class="hint">
                Проверьте параметры <code>DB_HOST</code>, <code>DB_USER</code>, <code>DB_PASS</code> и <code>DB_NAME</code> в файле <code>config.php</code>.<br>
                После исправления обновите страницу.
            </p>
            <details class="trace">
                <summary>Технические детали</summary>
                <?= htmlspecialchars($e->getMessage(), ENT_NOQUOTES, 'UTF-8'); ?>
            </details>
        </div>
        </body>
        </html>
        <?php
    } else {
        fwrite(STDERR, $message . PHP_EOL . $e->getMessage() . PHP_EOL);
    }
    exit;
}
