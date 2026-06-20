<?php
/**
 * Ежемесячный отчёт по письмам — отправляется 1-го числа каждого месяца.
 * Рекомендуемый crontab:
 *   0 8 1 * * php /var/www/cron_monthly_report.php >> /var/log/os_monthly_report.log 2>&1
 *
 * Или HTTP-вызов с токеном:
 *   GET /cron_monthly_report.php?token=<CRON_TOKEN>
 */

require_once __DIR__ . '/config.php';

use App\Services\EmailService;

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $expectedToken = getenv('CRON_TOKEN') ?: '';
    $providedToken = $_GET['token'] ?? '';
    if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещён'], JSON_ENCODE_FLAGS);
        exit;
    }
}

try {
    $db = getDBConnection();

    // Previous month boundaries
    $now        = new DateTime('now');
    $firstOfMonth = new DateTime('first day of last month 00:00:00');
    $lastOfMonth  = new DateTime('last day of last month 23:59:59');
    $monthLabel   = $firstOfMonth->format('F Y');
    $monthFrom    = $firstOfMonth->format('Y-m-d');
    $monthTo      = $lastOfMonth->format('Y-m-d');

    // Fetch regions
    $regions = $db->query("SELECT id, name_ru FROM regions WHERE is_active = TRUE ORDER BY name_ru")->fetchAll();

    $reportsQueued = 0;
    $smtpEnabled   = defined('SMTP_HOST') && SMTP_HOST !== '';

    foreach ($regions as $region) {
        $regionId   = (int)$region['id'];
        $regionName = $region['name_ru'];

        // Count letters
        $incomingCount = (int)$db->prepare("
            SELECT COUNT(*) FROM incoming_letters
            WHERE region_id = ? AND date BETWEEN ? AND ?
        ")->execute([$regionId, $monthFrom, $monthTo]) ?
            $db->query("SELECT COUNT(*) FROM incoming_letters WHERE region_id = {$regionId} AND date BETWEEN '{$monthFrom}' AND '{$monthTo}'")->fetchColumn() : 0;

        $outgoingCount = (int)$db->query("SELECT COUNT(*) FROM outgoing_letters WHERE region_id = {$regionId} AND date BETWEEN '{$monthFrom}' AND '{$monthTo}'")->fetchColumn();

        // Top organizations by incoming
        $topOrgs = $db->query("
            SELECT organization, COUNT(*) AS cnt
            FROM incoming_letters
            WHERE region_id = {$regionId} AND date BETWEEN '{$monthFrom}' AND '{$monthTo}'
            GROUP BY organization ORDER BY cnt DESC LIMIT 5
        ")->fetchAll();

        // Most active members
        $topMembers = $db->query("
            SELECT m.full_name, COUNT(DISTINCT lm.letter_id) AS cnt
            FROM letter_members lm
            JOIN os_members m ON lm.member_id = m.id
            JOIN incoming_letters il ON lm.letter_type = 'incoming' AND lm.letter_id = il.id
            WHERE il.region_id = {$regionId} AND il.date BETWEEN '{$monthFrom}' AND '{$monthTo}'
            GROUP BY m.id ORDER BY cnt DESC LIMIT 5
        ")->fetchAll();

        // Build HTML email
        $orgRows = array_map(fn($r) => "<tr><td style='padding:4px 8px'>" . htmlspecialchars($r['organization'], ENT_QUOTES) . "</td><td style='padding:4px 8px;text-align:center'>{$r['cnt']}</td></tr>", $topOrgs);
        $orgTable = $orgRows ? implode('', $orgRows) : '<tr><td colspan="2" style="padding:4px 8px;color:#999">Нет данных</td></tr>';

        $memberRows = array_map(fn($r) => "<tr><td style='padding:4px 8px'>" . htmlspecialchars($r['full_name'], ENT_QUOTES) . "</td><td style='padding:4px 8px;text-align:center'>{$r['cnt']}</td></tr>", $topMembers);
        $memberTable = $memberRows ? implode('', $memberRows) : '<tr><td colspan="2" style="padding:4px 8px;color:#999">Нет данных</td></tr>';

        $html = "
<html><body style='font-family:Arial,sans-serif;font-size:14px;color:#333;max-width:600px;margin:0 auto'>
<h2 style='color:#0d6efd;border-bottom:2px solid #0d6efd;padding-bottom:8px'>
  Ежемесячный отчёт — {$regionName}
</h2>
<p style='color:#666'>Период: {$firstOfMonth->format('d.m.Y')} — {$lastOfMonth->format('d.m.Y')}</p>

<table style='border-collapse:collapse;width:100%;margin-bottom:20px'>
  <tr>
    <td style='padding:12px;background:#e7f1ff;border-radius:8px;text-align:center;width:50%'>
      <div style='font-size:32px;font-weight:bold;color:#0d6efd'>{$incomingCount}</div>
      <div>Входящих писем</div>
    </td>
    <td style='width:16px'></td>
    <td style='padding:12px;background:#e7f1ff;border-radius:8px;text-align:center;width:50%'>
      <div style='font-size:32px;font-weight:bold;color:#198754'>{$outgoingCount}</div>
      <div>Исходящих писем</div>
    </td>
  </tr>
</table>

<h3 style='color:#333;font-size:15px'>Топ организаций (входящие)</h3>
<table style='border-collapse:collapse;width:100%;margin-bottom:20px'>
  <thead><tr style='background:#f4f6fb'>
    <th style='padding:6px 8px;text-align:left'>Организация</th>
    <th style='padding:6px 8px;text-align:center'>Писем</th>
  </tr></thead>
  <tbody>{$orgTable}</tbody>
</table>

<h3 style='color:#333;font-size:15px'>Активные члены ОС</h3>
<table style='border-collapse:collapse;width:100%;margin-bottom:20px'>
  <thead><tr style='background:#f4f6fb'>
    <th style='padding:6px 8px;text-align:left'>ФИО</th>
    <th style='padding:6px 8px;text-align:center'>Писем</th>
  </tr></thead>
  <tbody>{$memberTable}</tbody>
</table>

<hr style='border:none;border-top:1px solid #eee;margin:20px 0'>
<p style='font-size:12px;color:#999'>Журнал Общественного Совета · ежемесячный автоматический отчёт</p>
</body></html>";

        if (!$smtpEnabled) {
            continue;
        }

        // Send to all managers/admins of this region
        $recipients = $db->query("
            SELECT DISTINCT email FROM users
            WHERE is_active = TRUE
              AND email IS NOT NULL AND email != ''
              AND (region_id = {$regionId} OR role = 'admin')
              AND role IN ('admin', 'manager')
        ")->fetchAll(\PDO::FETCH_COLUMN);

        $subject = "Отчёт за " . $firstOfMonth->format('m.Y') . " — {$regionName}";
        foreach ($recipients as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            EmailService::enqueue($db, $email, $subject, $html, strip_tags($html));
            $reportsQueued++;
        }
    }

    $response = [
        'regions_processed' => count($regions),
        'emails_queued'     => $reportsQueued,
        'period'            => "{$monthFrom} — {$monthTo}",
        'timestamp'         => $now->format(DATE_ATOM),
    ];

    if ($isCli) {
        echo '[' . date('Y-m-d H:i:s') . '] regions=' . count($regions) . ' emails_queued=' . $reportsQueued . PHP_EOL;
    } else {
        echo json_encode($response, JSON_ENCODE_FLAGS);
    }
} catch (\Throwable $e) {
    error_log('cron_monthly_report failed: ' . $e->getMessage());
    if ($isCli) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_ENCODE_FLAGS);
}
