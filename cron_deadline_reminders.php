<?php
/**
 * Напоминания о дедлайнах входящих писем через Telegram.
 * Для каждого региона находит входящие письма без ответа, у которых срок
 * (15 рабочих дней от даты входящего) наступает через 3 дня, через 1 день
 * или уже просрочен, и шлёт одно сводное сообщение каждому пользователю
 * региона с ролью admin/moderator и привязанным Telegram.
 *
 * Запуск из CLI или по HTTP с CRON_TOKEN:
 *   php /path/to/cron_deadline_reminders.php
 *   GET /cron_deadline_reminders.php?token=<CRON_TOKEN>
 *
 * Рекомендуемый crontab:
 *   0 9 * * * php /var/www/cron_deadline_reminders.php >> /var/log/os_journal_reminders.log 2>&1
 */

require_once __DIR__ . '/config.php';

use App\Services\FileCache;
use App\Services\TelegramService;

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');

    $expectedToken = envValue('CRON_TOKEN');
    $headerToken = '';
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $headerToken = $headers['X-Cron-Token'] ?? $headers['x-cron-token'] ?? '';
    }
    $providedToken = $headerToken ?: ($_GET['token'] ?? '');
    if (!is_string($expectedToken) || $expectedToken === '' || !is_string($providedToken)
        || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещён'], JSON_ENCODE_FLAGS);
        exit;
    }
}

/**
 * Прибавляет N рабочих дней (пн–пт) — та же логика, что в cron_deadlines.php
 * и в триггере deadline_date (15 рабочих дней от даты входящего).
 */
function reminderAddWorkingDays(DateTime $date, int $days): DateTime
{
    $remaining = $days;
    while ($remaining > 0) {
        $date->modify('+1 day');
        if ((int)$date->format('N') < 6) { // 6 = Сб, 7 = Вс
            $remaining--;
        }
    }
    return $date;
}

/**
 * Формирует блок списка писем: «Вх.12 · Организация — до 15.07.2026»,
 * максимум $limit строк, дальше «…и ещё K».
 */
function reminderFormatLetters(array $letters, int $limit = 10): string
{
    $lines = [];
    foreach (array_slice($letters, 0, $limit) as $l) {
        $org = htmlspecialchars(mb_substr((string)($l['organization'] ?? ''), 0, 60), ENT_QUOTES, 'UTF-8');
        $num = htmlspecialchars((string)($l['kk_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lines[] = "• Вх.<b>{$l['seq']}</b> ({$num}) · {$org} — до {$l['due_label']}";
    }
    $rest = count($letters) - $limit;
    if ($rest > 0) {
        $lines[] = "…и ещё {$rest}";
    }
    return implode("\n", $lines);
}

try {
    $db = getDBConnection();

    if (!TelegramService::isConfigured()) {
        $msg = 'Telegram не настроен (TELEGRAM_BOT_TOKEN пуст)';
        if ($isCli) {
            echo '[SKIP] ' . $msg . PHP_EOL;
        } else {
            echo json_encode(['skipped' => $msg], JSON_ENCODE_FLAGS);
        }
        exit;
    }

    $cache = new FileCache();
    $today = new DateTime('today');

    // Входящие без ответа за последние 6 месяцев:
    // нет исходящего-ответа (LEFT JOIN по incoming_ref_id) и нет прямой связи.
    $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
    $stmt = $db->prepare("
        SELECT il.id, il.region_id, il.seq, il.date, il.deadline_date,
               il.organization, il.kk_number
        FROM incoming_letters il
        LEFT JOIN outgoing_letters o
               ON o.incoming_ref_id = il.id AND o.deleted_at IS NULL
        WHERE il.deleted_at IS NULL
          AND il.linked_outgoing_id IS NULL
          AND o.id IS NULL
          AND il.date >= ?
        ORDER BY il.region_id, il.date ASC
        LIMIT 500
    ");
    $stmt->execute([$sixMonthsAgo]);
    $letters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Группировка по регионам: [region_id => ['d3' => [], 'd1' => [], 'overdue' => []]]
    $byRegion = [];
    foreach ($letters as $letter) {
        if (empty($letter['date'])) {
            continue;
        }
        // deadline_date из БД, иначе 15 рабочих дней от даты входящего
        $due = !empty($letter['deadline_date'])
            ? new DateTime($letter['deadline_date'])
            : reminderAddWorkingDays(new DateTime($letter['date']), 15);
        $due->setTime(0, 0);

        $daysLeft = (int)$today->diff($due)->format('%r%a');

        $group = null;
        if ($daysLeft < 0) {
            $group = 'overdue';
        } elseif ($daysLeft === 1) {
            $group = 'd1';
        } elseif ($daysLeft === 3) {
            $group = 'd3';
        }
        if ($group === null) {
            continue;
        }

        $letter['due_label'] = $due->format('d.m.Y');
        $byRegion[(int)$letter['region_id']][$group][] = $letter;
    }

    $usersNotified = 0;
    $usersSkipped  = 0;
    $sendErrors    = 0;
    $todayKey      = $today->format('Y-m-d');
    $appUrl        = defined('APP_URL') ? rtrim(APP_URL, '/') : '';

    foreach ($byRegion as $regionId => $groups) {
        $d3      = $groups['d3'] ?? [];
        $d1      = $groups['d1'] ?? [];
        $overdue = $groups['overdue'] ?? [];
        $total   = count($d3) + count($d1) + count($overdue);
        if ($total === 0) {
            continue;
        }

        // Пользователи региона с привязанным Telegram и ролью admin/moderator
        $stmtU = $db->prepare("
            SELECT id, telegram_chat_id
            FROM users
            WHERE region_id = ?
              AND role IN ('admin', 'moderator')
              AND telegram_chat_id IS NOT NULL
              AND telegram_chat_id != ''
              AND is_active = TRUE
        ");
        $stmtU->execute([$regionId]);
        $users = $stmtU->fetchAll(PDO::FETCH_ASSOC);
        if (empty($users)) {
            continue;
        }

        // Сводное сообщение (HTML, всё пользовательское экранировано)
        $text = "⏰ <b>Напоминание: {$total} " . ($total === 1 ? 'письмо' : 'писем') . " требует ответа</b>\n";
        if (!empty($d3)) {
            $text .= "\n🟢 <b>Срок через 3 дня (" . count($d3) . "):</b>\n" . reminderFormatLetters($d3) . "\n";
        }
        if (!empty($d1)) {
            $text .= "\n🟡 <b>Срок завтра (" . count($d1) . "):</b>\n" . reminderFormatLetters($d1) . "\n";
        }
        if (!empty($overdue)) {
            $text .= "\n🔴 <b>Просрочено: " . count($overdue) . "</b>\n" . reminderFormatLetters($overdue) . "\n";
        }
        if ($appUrl) {
            $text .= "\n<a href=\"{$appUrl}/api/\">Открыть журнал</a>";
        }

        foreach ($users as $user) {
            $userId   = (int)$user['id'];
            $cacheKey = "reminder_sent_{$userId}_{$todayKey}";
            try {
                // Анти-спам: не слать, если сегодня уже слали
                if ($cache->get($cacheKey) !== null) {
                    $usersSkipped++;
                    continue;
                }
                if (TelegramService::sendMessage((string)$user['telegram_chat_id'], $text)) {
                    $cache->set($cacheKey, 1, 26 * 3600);
                    $usersNotified++;
                } else {
                    $sendErrors++;
                }
            } catch (\Throwable $e) {
                $sendErrors++;
                error_log("cron_deadline_reminders: failed for user {$userId}: " . $e->getMessage());
            }
        }
    }

    $response = [
        'letters_checked' => count($letters),
        'regions_alerted' => count($byRegion),
        'users_notified'  => $usersNotified,
        'users_skipped'   => $usersSkipped,
        'send_errors'     => $sendErrors,
        'timestamp'       => date(DATE_ATOM),
    ];

    if ($isCli) {
        echo '[' . date('Y-m-d H:i:s') . '] '
            . "checked={$response['letters_checked']} regions={$response['regions_alerted']} "
            . "notified={$usersNotified} skipped={$usersSkipped} errors={$sendErrors}"
            . PHP_EOL;
        if ($sendErrors > 0) {
            exit(1);
        }
    } else {
        echo json_encode($response, JSON_ENCODE_FLAGS);
    }
} catch (\Throwable $e) {
    error_log('cron_deadline_reminders failed: ' . $e->getMessage());
    if ($isCli) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_ENCODE_FLAGS);
}
