<?php
/**
 * Скрипт проверки входящих писем без ответа и отправки уведомлений через Pusher.
 * Можно запускать вручную (через браузер) или добавить в cron:
 *   php /path/to/cron_deadlines.php
 */

require_once __DIR__ . '/config.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');

    // Запуск из браузера допустим только при наличии корректного секретного токена.
    $expectedToken = envValue('CRON_TOKEN');
    $providedToken = $_GET['token'] ?? '';
    if (!is_string($expectedToken) || $expectedToken === '' || !is_string($providedToken)
        || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function addWorkingDaysPHP(DateTime $date, int $days): DateTime {
    $direction = $days >= 0 ? 1 : -1;
    $remaining = abs($days);
    while ($remaining > 0) {
        $date->modify(($direction > 0 ? '+1 day' : '-1 day'));
        $dayOfWeek = (int)$date->format('N'); // 6 = Sat, 7 = Sun
        if ($dayOfWeek < 6) {
            $remaining--;
        }
    }
    return $date;
}

function subtractWorkingDaysPHP(DateTime $date, int $days): DateTime {
    return addWorkingDaysPHP($date, -$days);
}

try {
    $db = getDBConnection();
    $stmt = $db->query("
        SELECT il.id, il.seq, il.date, il.organization, il.kk_number, il.region_id
        FROM incoming_letters il
        WHERE il.linked_outgoing_id IS NULL
        ORDER BY il.date ASC
    ");
    $letters = $stmt->fetchAll();

    $now = new DateTime('now');
    $notifications = [];

    foreach ($letters as $letter) {
        if (empty($letter['date'])) {
            continue;
        }
        $date = new DateTime($letter['date']);
        $due = addWorkingDaysPHP(clone $date, 15);
        $warnFrom = subtractWorkingDaysPHP(clone $due, 3);

        $status = 'normal';
        if ($now > $due) {
            $status = 'overdue';
        } elseif ($now >= $warnFrom) {
            $status = 'warning';
        }

        if ($status === 'normal') {
            continue;
        }

        $daysLeft = (int)$now->diff($due)->format('%r%a');
        $notifications[] = [
            'action' => 'deadline',
            'status' => $status,
            'id' => (int)$letter['id'],
            'seq' => (int)$letter['seq'],
            'kk_number' => $letter['kk_number'],
            'organization' => $letter['organization'],
            'due_date' => $due->format('Y-m-d'),
            'days_left' => $daysLeft,
            'region_id' => (int)$letter['region_id'],
        ];
    }

    foreach ($notifications as $payload) {
        pusherTrigger('council-deadlines', 'deadline-warning', $payload);
    }

    $response = [
        'total_checked' => count($letters),
        'notifications_sent' => count($notifications),
        'timestamp' => $now->format(DATE_ATOM),
    ];
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('cron_deadlines failed: ' . $e->getMessage());
    echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_UNESCAPED_UNICODE);
}


