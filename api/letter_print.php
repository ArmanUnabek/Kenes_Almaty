<?php
/**
 * GET /api/letter_print.php?type=incoming&id=N
 * Генерирует HTML-страницу письма, оптимизированную для печати.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

checkAuth();

$type = $_GET['type'] ?? 'incoming';
$id   = (int)($_GET['id'] ?? 0);

if (!in_array($type, ['incoming', 'outgoing'], true) || $id <= 0) {
    http_response_code(400);
    echo 'Неверные параметры запроса';
    exit;
}

$db       = getDBConnection();
$regionId = getCurrentRegionId();
$table    = $type === 'incoming' ? 'incoming_letters' : 'outgoing_letters';

// Include region_id in the query so admins cannot cross active-region boundaries
// (canAccessRegion returns true for all admins regardless of active region)
if ($regionId) {
    $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ? AND region_id = ?");
    $stmt->execute([$id, $regionId]);
} else {
    $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
}
$letter = $stmt->fetch();

if (!$letter) {
    http_response_code(404);
    echo 'Письмо не найдено';
    exit;
}

if (!canAccessRegion((int)($letter['region_id'] ?? 0))) {
    http_response_code(403);
    echo 'Доступ запрещён';
    exit;
}

// Fetch members
$stmtM = $db->prepare("
    SELECT m.full_name, lm.is_lead
    FROM letter_members lm
    JOIN os_members m ON m.id = lm.member_id
    WHERE lm.letter_id = ? AND lm.letter_type = ?
    ORDER BY lm.is_lead DESC, m.full_name
");
$stmtM->execute([$id, $type]);
$members = $stmtM->fetchAll();

// Fetch recipients
$stmtR = $db->prepare("SELECT recipient FROM letter_recipients WHERE letter_id = ? AND letter_type = ?");
$stmtR->execute([$id, $type]);
$recipients = array_column($stmtR->fetchAll(), 'recipient');

$typeLabel = $type === 'incoming' ? 'Входящее письмо' : 'Исходящее письмо';

if ($type === 'incoming') {
    $num = 'Вх. №' . ($letter['seq'] ?? '—');
    if (!empty($letter['kk_number'])) $num .= ' / ' . $letter['kk_number'];
} else {
    $num = 'Исх. №' . ($letter['outgoing_number'] ?? ('Исх.' . ($letter['seq'] ?? '—')));
}

$printDate = date('d.m.Y H:i');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($typeLabel . ' — ' . $num) ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body {
    font-family: 'Times New Roman', Times, serif;
    font-size: 12pt;
    margin: 2cm;
    color: #000;
    background: #fff;
  }
  .header { text-align: center; margin-bottom: 1.5cm; }
  .header h1 { font-size: 16pt; font-weight: bold; margin: 0 0 4pt; }
  .header .num { font-size: 13pt; color: #444; margin: 0; }
  .header .date { font-size: 9pt; color: #777; margin-top: 4pt; }
  table.fields {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1cm;
  }
  table.fields td {
    padding: 7px 10px;
    vertical-align: top;
    border: 1px solid #bbb;
    font-size: 11pt;
    line-height: 1.4;
  }
  table.fields td:first-child {
    width: 30%;
    font-weight: bold;
    background: #f7f7f7;
    white-space: nowrap;
  }
  .member-lead::after { content: ' (ведущий)'; font-size: 9pt; color: #666; }
  ul.plain { list-style: none; margin: 0; padding: 0; }
  ul.plain li { padding: 2pt 0; }
  .stamp-area {
    margin-top: 2cm;
    display: flex;
    justify-content: space-between;
  }
  .stamp-box {
    width: 45%;
    border-top: 1px solid #000;
    padding-top: 6pt;
    font-size: 10pt;
    text-align: center;
    color: #444;
  }
  @media screen {
    .no-screen { display: none; }
    body { max-width: 900px; margin: 2rem auto; padding: 2rem; box-shadow: 0 0 20px rgba(0,0,0,.15); }
  }
  @media print {
    .print-btn { display: none !important; }
    body { margin: 1.5cm; }
  }
  .print-btn {
    display: inline-block;
    margin: 0 auto 1.5rem;
    padding: 0.5rem 2rem;
    background: #1D4ED8;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 11pt;
    cursor: pointer;
    font-family: Arial, sans-serif;
  }
  .print-btn:hover { background: #1E40AF; }
  .actions { text-align: center; margin-bottom: 1.5cm; }
</style>
</head>
<body>

<div class="actions">
  <button class="print-btn" onclick="window.print()">🖨️ Печать</button>
  <button class="print-btn" style="background:#6B7280;margin-left:8px;" onclick="window.close()">✕ Закрыть</button>
</div>

<div class="header">
  <h1><?= htmlspecialchars($typeLabel) ?></h1>
  <p class="num"><?= htmlspecialchars($num) ?></p>
  <p class="date">Распечатано: <?= htmlspecialchars($printDate) ?></p>
</div>

<table class="fields">
  <tr>
    <td>Дата</td>
    <td><?= htmlspecialchars($letter['date'] ?? '—') ?></td>
  </tr>
<?php if ($type === 'incoming'): ?>
  <tr>
    <td>Организация</td>
    <td><?= htmlspecialchars($letter['organization'] ?? '—') ?></td>
  </tr>
  <tr>
    <td>Рег. номер (ҚК)</td>
    <td><?= htmlspecialchars($letter['kk_number'] ?? '—') ?></td>
  </tr>
  <tr>
    <td>Категория</td>
    <td><?= htmlspecialchars($letter['category'] ?? 'KK') ?></td>
  </tr>
<?php else: ?>
  <tr>
    <td>Исходящий №</td>
    <td><?= htmlspecialchars($letter['outgoing_number'] ?? '—') ?></td>
  </tr>
  <tr>
    <td>Организация</td>
    <td><?= htmlspecialchars($letter['organization'] ?? '—') ?></td>
  </tr>
  <tr>
    <td>Тип письма</td>
    <td><?= htmlspecialchars($letter['outgoing_type'] ?? 'gov') ?></td>
  </tr>
<?php endif; ?>
  <tr>
    <td>Тема</td>
    <td><?= htmlspecialchars($letter['subject'] ?? '—') ?></td>
  </tr>
  <tr>
    <td>Примечание</td>
    <td><?= htmlspecialchars($letter['note'] ?? '—') ?></td>
  </tr>
<?php if ($recipients): ?>
  <tr>
    <td>Адресаты</td>
    <td><?= htmlspecialchars(implode(', ', $recipients)) ?></td>
  </tr>
<?php endif; ?>
<?php if ($members): ?>
  <tr>
    <td>Ответственные</td>
    <td>
      <ul class="plain">
        <?php foreach ($members as $m): ?>
        <li class="<?= $m['is_lead'] ? 'member-lead' : '' ?>"><?= htmlspecialchars($m['full_name']) ?></li>
        <?php endforeach; ?>
      </ul>
    </td>
  </tr>
<?php endif; ?>
</table>

<div class="stamp-area">
  <div class="stamp-box">Исполнитель: ____________________</div>
  <div class="stamp-box">Дата / подпись: ____________________</div>
</div>

</body>
</html>
