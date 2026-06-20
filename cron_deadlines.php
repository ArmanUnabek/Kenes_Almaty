<?php
/**
 * Скрипт проверки входящих писем без ответа.
 * Отправляет Pusher-события и email-уведомления назначенным членам.
 * Запуск из CLI или по HTTP с CRON_TOKEN:
 *   php /path/to/cron_deadlines.php
 *   GET /cron_deadlines.php?token=<CRON_TOKEN>
 *
 * Рекомендуемый crontab:
 *   0 9 * * 1-5 php /var/www/cron_deadlines.php >> /var/log/os_journal_deadlines.log 2>&1
 */

require_once __DIR__ . '/config.php';

use App\Services\EmailService;

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');

    $expectedToken = envValue('CRON_TOKEN');
    $providedToken = $_GET['token'] ?? '';
    if (!is_string($expectedToken) || $expectedToken === '' || !is_string($providedToken)
        || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещён'], JSON_ENCODE_FLAGS);
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

function buildDeadlineEmailHtml(array $payload, string $statusLabel): string
{
    $org    = htmlspecialchars($payload['organization'] ?? '', ENT_QUOTES, 'UTF-8');
    $seq    = (int)$payload['seq'];
    $num    = htmlspecialchars($payload['kk_number'] ?? '', ENT_QUOTES, 'UTF-8');
    $due    = htmlspecialchars($payload['due_date'] ?? '', ENT_QUOTES, 'UTF-8');
    $days   = (int)$payload['days_left'];
    $badge  = $payload['status'] === 'overdue'
        ? '<span style="color:#dc3545;font-weight:bold">ПРОСРОЧЕНО</span>'
        : '<span style="color:#fd7e14;font-weight:bold">СКОРО СРОК</span>';

    return "
<html><body style='font-family:Arial,sans-serif;font-size:14px;color:#333'>
<p>Уважаемый(ая) коллега,</p>
<p>Напоминаем о входящем письме, требующем ответа:</p>
<table style='border-collapse:collapse;width:100%;max-width:560px'>
  <tr><td style='padding:6px 12px;background:#f4f6fb;font-weight:bold'>Вх. №</td>
      <td style='padding:6px 12px'>{$seq}</td></tr>
  <tr><td style='padding:6px 12px;background:#f4f6fb;font-weight:bold'>Рег. номер</td>
      <td style='padding:6px 12px'>{$num}</td></tr>
  <tr><td style='padding:6px 12px;background:#f4f6fb;font-weight:bold'>Организация</td>
      <td style='padding:6px 12px'>{$org}</td></tr>
  <tr><td style='padding:6px 12px;background:#f4f6fb;font-weight:bold'>Срок ответа</td>
      <td style='padding:6px 12px'>{$due}</td></tr>
  <tr><td style='padding:6px 12px;background:#f4f6fb;font-weight:bold'>Статус</td>
      <td style='padding:6px 12px'>{$badge} ({$statusLabel}, осталось {$days} дн.)</td></tr>
</table>
<p style='margin-top:16px'>Пожалуйста, подготовьте ответ на письмо.</p>
<hr style='border:none;border-top:1px solid #eee;margin:20px 0'>
<p style='font-size:12px;color:#999'>Журнал Общественного Совета · автоматическое уведомление</p>
</body></html>";
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

    $now           = new DateTime('now');
    $notifications = [];
    $emailsQueued  = 0;
    $smtpEnabled   = defined('SMTP_HOST') && SMTP_HOST !== '';

    foreach ($letters as $letter) {
        if (empty($letter['date'])) {
            continue;
        }
        $date     = new DateTime($letter['date']);
        $due      = addWorkingDaysPHP(clone $date, 15);
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
        $payload  = [
            'action'       => 'deadline',
            'status'       => $status,
            'id'           => (int)$letter['id'],
            'seq'          => (int)$letter['seq'],
            'kk_number'    => $letter['kk_number'],
            'organization' => $letter['organization'],
            'due_date'     => $due->format('Y-m-d'),
            'days_left'    => $daysLeft,
            'region_id'    => (int)$letter['region_id'],
        ];
        $notifications[] = $payload;

        // Email назначенным членам письма
        if ($smtpEnabled) {
            $stmtMembers = $db->prepare("
                SELECT m.email, m.full_name
                FROM letter_members lm
                JOIN os_members m ON lm.member_id = m.id
                WHERE lm.letter_type = 'incoming'
                  AND lm.letter_id = ?
                  AND m.email IS NOT NULL
                  AND m.email != ''
                  AND m.status = 'active'
            ");
            $stmtMembers->execute([(int)$letter['id']]);
            $members = $stmtMembers->fetchAll();

            $statusLabel = $status === 'overdue' ? 'просрочено' : 'предупреждение';
            $subject     = ($status === 'overdue' ? '[ПРОСРОЧЕНО] ' : '[Срок!] ')
                . 'Входящее письмо Вх.' . (int)$letter['seq']
                . ' — ' . ($letter['organization'] ?? '');
            $bodyHtml    = buildDeadlineEmailHtml($payload, $statusLabel);

            foreach ($members as $member) {
                if (!filter_var($member['email'], FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                EmailService::enqueue($db, $member['email'], $subject, $bodyHtml, strip_tags($bodyHtml));
                $emailsQueued++;
            }
        }
    }

    foreach ($notifications as $p) {
        pusherTrigger('council-deadlines', 'deadline-warning', $p);
    }

    $response = [
        'total_checked'      => count($letters),
        'notifications_sent' => count($notifications),
        'emails_queued'      => $emailsQueued,
        'timestamp'          => $now->format(DATE_ATOM),
    ];

    if ($isCli) {
        echo '[' . date('Y-m-d H:i:s') . '] '
            . "checked={$response['total_checked']} alerts={$response['notifications_sent']} emails_queued={$emailsQueued}"
            . PHP_EOL;
    } else {
        echo json_encode($response, JSON_ENCODE_FLAGS);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('cron_deadlines failed: ' . $e->getMessage());
    if ($isCli) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_ENCODE_FLAGS);
}
