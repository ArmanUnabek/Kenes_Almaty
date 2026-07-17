<?php
/**
 * Health-check endpoint для внешнего аптайм-мониторинга.
 *
 * Работает БЕЗ авторизации (мониторинг дёргает извне), но не раскрывает
 * ничего чувствительного: только статусы и обезличенные метрики — без путей,
 * имён БД и стектрейсов.
 *
 * Формат ответа:
 *   { "status": "ok"|"degraded"|"down", "checks": {...}, "timestamp": "..." }
 *
 * HTTP-коды: 200 при ok/degraded, 503 при down (БД недоступна).
 *
 * Опционально: если задан env HEALTH_TOKEN (или HEALTH_CHECK_TOKEN) и он НЕ
 * совпадает с ?token= / заголовком X-Health-Token — отдаётся урезанная версия
 * (только status + timestamp), чтобы детальные метрики видел лишь мониторинг.
 *
 * Всё обёрнуто в try/catch, чтобы сам health-эндпоинт никогда не падал 500.
 */

$STUCK_HOURS = 6;          // очередь «застряла», если элемент висит дольше N часов
$DISK_WARN_FREE_PCT = 10;  // предупреждение, если свободно меньше N% диска

// Определяем «полный» доступ до подключения config, чтобы даже сбой конфига
// не мешал отдать урезанный ответ.
$expectedToken = (string)(getenv('HEALTH_TOKEN') ?: getenv('HEALTH_CHECK_TOKEN') ?: '');
$providedToken = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? ''));
$fullDetail = ($expectedToken === '') || hash_equals($expectedToken, $providedToken);

$status  = 'down';
$checks  = [];
$metrics = [];
$httpCode = 503;

try {
    require_once __DIR__ . '/../config.php';

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        // Ответ мониторинга не должен кэшироваться промежуточными узлами.
        header('Cache-Control: no-store, max-age=0');
    }

    $degraded = false;

    // ── (а) База данных ──────────────────────────────────────────────────────
    $dbOk = false;
    try {
        $t0 = microtime(true);
        $db = getDBConnection();
        $db->query('SELECT 1');
        $dbMs = round((microtime(true) - $t0) * 1000, 1);
        $dbOk = true;
        $checks['database'] = ['ok' => true, 'response_ms' => $dbMs];
    } catch (\Throwable $e) {
        // Не раскрываем текст исключения (может содержать DSN/имя БД).
        $checks['database'] = ['ok' => false];
    }

    // БД недоступна → сервис down (503). Остальное всё равно посчитаем.
    if (!$dbOk) {
        $status = 'down';
        $httpCode = 503;
    } else {
        $status = 'ok';
        $httpCode = 200;
    }

    // ── (б) Диск ─────────────────────────────────────────────────────────────
    try {
        $root = defined('APP_ROOT') ? APP_ROOT : __DIR__;
        $free  = @disk_free_space($root);
        $total = @disk_total_space($root);
        if ($free !== false && $total !== false && $total > 0) {
            $freePct = round(($free / $total) * 100, 1);
            $diskOk = $freePct >= $DISK_WARN_FREE_PCT;
            $checks['disk'] = [
                'ok'          => $diskOk,
                'free_bytes'  => (int)$free,
                'total_bytes' => (int)$total,
                'free_pct'    => $freePct,
            ];
            if (!$diskOk) {
                $degraded = true;
            }
        } else {
            $checks['disk'] = ['ok' => null];
        }
    } catch (\Throwable $e) {
        $checks['disk'] = ['ok' => null];
    }

    // ── (в) Очереди email_queue / sms_queue ──────────────────────────────────
    if ($dbOk) {
        foreach (['email_queue', 'sms_queue'] as $table) {
            try {
                // Таблица может отсутствовать — тогда просто пропускаем.
                $stmt = $db->query(
                    "SELECT
                        SUM(status IN ('queued','failed')) AS pending,
                        SUM(status IN ('queued','failed')
                            AND created_at < (NOW() - INTERVAL {$STUCK_HOURS} HOUR)) AS stuck
                     FROM {$table}"
                );
                $row = $stmt->fetch();
                $pending = (int)($row['pending'] ?? 0);
                $stuck   = (int)($row['stuck'] ?? 0);
                $queueOk = $stuck === 0;
                $checks[$table] = [
                    'ok'      => $queueOk,
                    'pending' => $pending,
                    'stuck'   => $stuck,
                ];
                if (!$queueOk) {
                    $degraded = true;
                }
            } catch (\Throwable $e) {
                // Нет таблицы / нет доступа — не отражаем в checks.
            }
        }
    }

    // ── (г) Версия PHP ───────────────────────────────────────────────────────
    $metrics['php_version'] = PHP_VERSION;

    // Итоговый статус: down имеет приоритет, иначе degraded при любом warn.
    if ($status !== 'down' && $degraded) {
        $status = 'degraded';
        $httpCode = 200;
    }
} catch (\Throwable $e) {
    // Любой непредвиденный сбой самого health — считаем сервис down, но не 500.
    $status = 'down';
    $httpCode = 503;
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
}

$flags = defined('JSON_ENCODE_FLAGS')
    ? JSON_ENCODE_FLAGS
    : (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

http_response_code($httpCode);

if (!$fullDetail) {
    // Урезанная версия: наружу без валидного токена — только статус.
    echo json_encode(['status' => $status, 'timestamp' => date('c')], $flags);
    exit;
}

echo json_encode([
    'status'    => $status,
    'checks'    => $checks,
    'metrics'   => $metrics,
    'timestamp' => date('c'),
], $flags);
