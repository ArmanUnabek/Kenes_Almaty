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
    $expectedToken = envValue('CRON_TOKEN');
    $headerToken = '';
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $headerToken = $headers['X-Cron-Token'] ?? $headers['x-cron-token'] ?? '';
    }
    $providedToken = $headerToken ?: ($_GET['token'] ?? '');
    if (!is_string($expectedToken) || $expectedToken === ''
        || !is_string($providedToken)
        || !hash_equals($expectedToken, $providedToken)) {
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
    $regionIds = array_map(fn($r) => (int)$r['id'], $regions);

    if (empty($regionIds)) {
        if ($isCli) {
            echo '[' . date('Y-m-d H:i:s') . '] regions=0 emails_queued=0' . PHP_EOL;
        } else {
            echo json_encode([
                'regions_processed' => 0,
                'emails_queued'     => 0,
                'period'            => "{$monthFrom} — {$monthTo}",
                'timestamp'         => (new DateTime('now'))->format(DATE_ATOM),
            ], JSON_ENCODE_FLAGS);
        }
        exit;
    }

    $reportsQueued = 0;
    $smtpEnabled   = defined('SMTP_HOST') && SMTP_HOST !== '';

    // Batch: counts per region (1 query for incoming, 1 for outgoing)
    $countsByRegion = [];
    if (!empty($regionIds)) {
        $inList = implode(',', $regionIds);
        $stmtCounts = $db->prepare("
            SELECT region_id, 'incoming' AS t, COUNT(*) AS cnt
            FROM incoming_letters WHERE region_id IN ($inList) AND date BETWEEN ? AND ?
            GROUP BY region_id
            UNION ALL
            SELECT region_id, 'outgoing' AS t, COUNT(*) AS cnt
            FROM outgoing_letters WHERE region_id IN ($inList) AND date BETWEEN ? AND ?
            GROUP BY region_id
        ");
        $stmtCounts->execute([$monthFrom, $monthTo, $monthFrom, $monthTo]);
        foreach ($stmtCounts->fetchAll() as $row) {
            $rid = (int)$row['region_id'];
            $countsByRegion[$rid][$row['t']] = (int)$row['cnt'];
        }
    }

    // Batch: top organizations per region (1 query, grouped in PHP)
    $topOrgsByRegion = [];
    if (!empty($regionIds)) {
        $stmtOrgs = $db->prepare("
            SELECT region_id, organization, cnt FROM (
                SELECT region_id, organization, COUNT(*) AS cnt,
                       ROW_NUMBER() OVER (PARTITION BY region_id ORDER BY COUNT(*) DESC) AS rn
                FROM incoming_letters
                WHERE region_id IN ($inList) AND date BETWEEN ? AND ?
                GROUP BY region_id, organization
            ) ranked WHERE rn <= 5
        ");
        $stmtOrgs->execute([$monthFrom, $monthTo]);
        foreach ($stmtOrgs->fetchAll() as $row) {
            $topOrgsByRegion[(int)$row['region_id']][] = $row;
        }
    }

    // Batch: most active members per region (1 query, grouped in PHP)
    $topMembersByRegion = [];
    if (!empty($regionIds)) {
        $stmtMembers = $db->prepare("
            SELECT il.region_id, m.full_name, cnt FROM (
                SELECT il2.region_id, lm.member_id, COUNT(DISTINCT lm.letter_id) AS cnt,
                       ROW_NUMBER() OVER (PARTITION BY il2.region_id ORDER BY COUNT(DISTINCT lm.letter_id) DESC) AS rn
                FROM letter_members lm
                JOIN incoming_letters il2 ON lm.letter_type = 'incoming' AND lm.letter_id = il2.id
                WHERE il2.region_id IN ($inList) AND il2.date BETWEEN ? AND ?
                GROUP BY il2.region_id, lm.member_id
            ) ranked
            JOIN os_members m ON ranked.member_id = m.id
            WHERE rn <= 5
        ");
        $stmtMembers->execute([$monthFrom, $monthTo]);
        foreach ($stmtMembers->fetchAll() as $row) {
            $topMembersByRegion[(int)$row['region_id']][] = $row;
        }
    }

    // Batch: recipients per region (1 query)
    $recipientsByRegion = [];
    if ($smtpEnabled && !empty($regionIds)) {
        $stmtRecip = $db->prepare("
            SELECT region_id, email FROM users
            WHERE is_active = TRUE
              AND email IS NOT NULL AND email != ''
              AND role IN ('admin', 'moderator')
              AND (region_id IN ($inList) OR role = 'admin')
        ");
        $stmtRecip->execute();
        foreach ($stmtRecip->fetchAll() as $row) {
            $recipientsByRegion[(int)$row['region_id']][] = $row['email'];
            if ((int)$row['region_id'] === 0) {
                continue;
            }
        }
    }

    foreach ($regions as $region) {
        $regionId   = (int)$region['id'];
        $regionName = $region['name_ru'];

        $incomingCount = $countsByRegion[$regionId]['incoming'] ?? 0;
        $outgoingCount = $countsByRegion[$regionId]['outgoing'] ?? 0;
        $topOrgs = $topOrgsByRegion[$regionId] ?? [];
        $topMembers = $topMembersByRegion[$regionId] ?? [];

        // Build HTML email
        $regionNameEsc = htmlspecialchars($regionName, ENT_QUOTES, 'UTF-8');
        $orgRows = array_map(fn($r) => "<tr><td style='padding:4px 8px'>" . htmlspecialchars($r['organization'], ENT_QUOTES) . "</td><td style='padding:4px 8px;text-align:center'>{$r['cnt']}</td></tr>", $topOrgs);
        $orgTable = $orgRows ? implode('', $orgRows) : '<tr><td colspan="2" style="padding:4px 8px;color:#999">Нет данных</td></tr>';

        $memberRows = array_map(fn($r) => "<tr><td style='padding:4px 8px'>" . htmlspecialchars($r['full_name'], ENT_QUOTES) . "</td><td style='padding:4px 8px;text-align:center'>{$r['cnt']}</td></tr>", $topMembers);
        $memberTable = $memberRows ? implode('', $memberRows) : '<tr><td colspan="2" style="padding:4px 8px;color:#999">Нет данных</td></tr>';

        $html = "
<html><body style='font-family:Arial,sans-serif;font-size:14px;color:#333;max-width:600px;margin:0 auto'>
<h2 style='color:#0d6efd;border-bottom:2px solid #0d6efd;padding-bottom:8px'>
  Ежемесячный отчёт — {$regionNameEsc}
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

        // Send to all moderators/admins of this region
        $recipients = array_unique(array_merge(
            $recipientsByRegion[$regionId] ?? [],
            $recipientsByRegion[0] ?? []  // admins (region_id=0 or NULL)
        ));

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
