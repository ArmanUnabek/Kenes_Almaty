<?php
/**
 * Обработчик очереди email-уведомлений.
 * Запуск из CLI:   php /path/to/cron_send_emails.php
 * Запуск через HTTP: GET /cron_send_emails.php?token=<CRON_TOKEN>&batch=20
 *
 * Рекомендуемый crontab (каждые 5 минут):
 *   @every5min php /var/www/cron_send_emails.php >> /var/log/os_journal_mail.log 2>&1
 */

require_once __DIR__ . '/config.php';

use App\Services\EmailService;

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');

    $expectedToken = envValue('CRON_TOKEN');
    $providedToken = $_GET['token'] ?? '';
    if (!is_string($expectedToken) || $expectedToken === ''
        || !is_string($providedToken)
        || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещён'], JSON_ENCODE_FLAGS);
        exit;
    }
}

if (!defined('SMTP_HOST') || SMTP_HOST === '') {
    $msg = 'SMTP_HOST не настроен — отправка email невозможна';
    if ($isCli) {
        fwrite(STDERR, $msg . PHP_EOL);
        exit(1);
    }
    http_response_code(503);
    echo json_encode(['error' => $msg], JSON_ENCODE_FLAGS);
    exit;
}

$batchSize = min(50, max(1, (int)($_GET['batch'] ?? 10)));

try {
    $db     = getDBConnection();
    $result = EmailService::processQueue($db, $batchSize);

    $response = array_merge($result, [
        'batch_size' => $batchSize,
        'timestamp'  => date(DATE_ATOM),
    ]);

    if ($isCli) {
        echo '[' . date('Y-m-d H:i:s') . '] '
            . "sent={$result['sent']} failed={$result['failed']} skipped={$result['skipped']}"
            . PHP_EOL;
    } else {
        echo json_encode($response, JSON_ENCODE_FLAGS);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('cron_send_emails failed: ' . $e->getMessage());
    if ($isCli) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_ENCODE_FLAGS);
}
