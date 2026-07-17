<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\Middleware\CsrfMiddleware;
use App\Services\EmailService;
use App\Middleware\RateLimiter;
use App\Services\NotificationRecipientPolicy;

header('Content-Type: application/json; charset=utf-8');

checkAuth();

$db = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── Лента событий (?feed=1): доступна любому авторизованному пользователю ──
// Уведомления генерируются на лету из данных региона пользователя,
// без отдельной таблицы. «Прочитанность» считается на клиенте (localStorage).
if ($method === 'GET' && isset($_GET['feed'])) {
    $regionId = resolveRegionIdForRead(); // null = админ без активного региона (все регионы)

    $items = [];
    $regionSql = $regionId !== null ? ' AND region_id = ?' : '';
    $regionArg = $regionId !== null ? [$regionId] : [];

    // 1) Новые входящие письма за последние 7 дней (без мягко удалённых)
    $stmt = $db->prepare(
        "SELECT id, seq, organization, subject, date, created_at
         FROM incoming_letters
         WHERE deleted_at IS NULL AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)" . $regionSql .
        " ORDER BY date DESC, id DESC LIMIT 20"
    );
    $stmt->execute($regionArg);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'type'   => 'letter_new',
            'title'  => trim(($r['organization'] ?? '') . ($r['subject'] ? ' — ' . mb_substr($r['subject'], 0, 120) : '')),
            'date'   => $r['date'],
            'ts'     => $r['created_at'] ?: ($r['date'] . ' 00:00:00'),
            'ref_id' => (int)$r['id'],
            'seq'    => (int)$r['seq'],
            'tab'    => 'incoming',
        ];
    }

    // 2) Письма без ответа: дедлайн в ближайшие 3 дня и просроченные
    $stmt = $db->prepare(
        "SELECT id, seq, organization, deadline_date
         FROM incoming_letters
         WHERE deleted_at IS NULL AND linked_outgoing_id IS NULL AND deadline_date IS NOT NULL
           AND deadline_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)" . $regionSql .
        " ORDER BY deadline_date ASC LIMIT 30"
    );
    $stmt->execute($regionArg);
    $today = date('Y-m-d');
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'type'   => $r['deadline_date'] < $today ? 'deadline_overdue' : 'deadline_soon',
            'title'  => trim((string)($r['organization'] ?? '')),
            'date'   => $r['deadline_date'],
            'ts'     => $r['deadline_date'] . ' 00:00:00',
            'ref_id' => (int)$r['id'],
            'seq'    => (int)$r['seq'],
            'tab'    => 'incoming',
        ];
    }

    // 3) Новые обращения граждан
    $stmt = $db->prepare(
        "SELECT id, appeal_number, subject, created_at
         FROM citizen_appeals
         WHERE status = 'new'" . $regionSql .
        " ORDER BY created_at DESC LIMIT 20"
    );
    $stmt->execute($regionArg);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'type'   => 'appeal_new',
            'title'  => trim(($r['appeal_number'] ? $r['appeal_number'] . ': ' : '') . mb_substr((string)$r['subject'], 0, 120)),
            'date'   => substr((string)$r['created_at'], 0, 10),
            'ts'     => $r['created_at'],
            'ref_id' => (int)$r['id'],
            'tab'    => 'appeals',
        ];
    }

    // 4) Ближайшие мероприятия (3 дня вперёд, включая сегодня)
    $stmt = $db->prepare(
        "SELECT id, title, event_date, location
         FROM events
         WHERE event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)" . $regionSql .
        " ORDER BY event_date ASC LIMIT 10"
    );
    $stmt->execute($regionArg);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'type'   => 'event_upcoming',
            'title'  => trim((string)$r['title'] . ($r['location'] ? ' — ' . $r['location'] : '')),
            'date'   => $r['event_date'],
            'ts'     => $r['event_date'] . ' 00:00:00',
            'ref_id' => (int)$r['id'],
            'tab'    => 'events',
        ];
    }

    // Сортировка: просроченные и ближайшие дедлайны первыми, далее по свежести
    $prio = ['deadline_overdue' => 0, 'deadline_soon' => 1, 'appeal_new' => 2, 'letter_new' => 3, 'event_upcoming' => 3];
    usort($items, function ($a, $b) use ($prio) {
        $pa = $prio[$a['type']] ?? 9;
        $pb = $prio[$b['type']] ?? 9;
        if ($pa !== $pb) return $pa <=> $pb;
        return strcmp((string)$b['ts'], (string)$a['ts']);
    });
    $items = array_slice($items, 0, 50);

    // unread_count считается на клиенте по localStorage; здесь — общее число.
    echo json_encode(['items' => $items, 'unread_count' => count($items)], JSON_ENCODE_FLAGS);
    exit;
}

requireRole(['admin', 'moderator']);

if ($method === 'PATCH') {
    CsrfMiddleware::requireVerification();
    requireRole(['admin']);
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($data['id'] ?? 0);
    $action = $data['action'] ?? '';
    if ($id <= 0 || $action !== 'retry') {
        http_response_code(400);
        echo json_encode(['error' => 'id и action=retry обязательны'], JSON_ENCODE_FLAGS);
        exit;
    }
    $stmt = $db->prepare("UPDATE email_queue SET status = 'queued', error = NULL, sent_at = NULL WHERE id = ? AND status = 'failed'");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Запись не найдена или статус не failed'], JSON_ENCODE_FLAGS);
        exit;
    }
    echo json_encode(['message' => 'Письмо поставлено в очередь повторно'], JSON_ENCODE_FLAGS);
    exit;
}

if ($method === 'POST') {
    $userId = (int)$_SESSION['user_id'];
    CsrfMiddleware::requireVerification();
    RateLimiter::requireCheck('notify_' . $userId, 10, 3600);
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $to = trim((string)($data['to'] ?? ''));
    $subject = trim((string)($data['subject'] ?? 'Уведомление ОС'));
    $body = (string)($data['body_html'] ?? '');
    $body = strip_tags($body, '<p><br><b><strong><i><em><u><a><ul><ol><li><h1><h2><h3><h4><h5><h6><table><thead><tbody><tr><td><th><div><span>');
    $body = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $body);
    $body = preg_replace('/javascript\s*:/i', '', $body);
    if ($to === '' || $body === '') {
        http_response_code(400);
        echo json_encode(['error' => 'to и body_html обязательны'], JSON_ENCODE_FLAGS);
        exit;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['error' => 'Некорректный email'], JSON_ENCODE_FLAGS);
        exit;
    }
    NotificationRecipientPolicy::assertAllowed($db, $to);
    $inReplyTo = !empty($data['in_reply_to']) ? trim($data['in_reply_to']) : null;
    $threadId = !empty($data['thread_id']) ? trim($data['thread_id']) : null;
    $messageId = EmailService::enqueue($db, $to, $subject, $body, strip_tags($body), $inReplyTo, $threadId);
    echo json_encode(['message' => 'Письмо поставлено в очередь', 'message_id' => $messageId], JSON_ENCODE_FLAGS);
    exit;
}

$limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
$stmt = $db->prepare('SELECT id, recipient_email, subject, status, message_id, in_reply_to, thread_id, created_at, sent_at FROM email_queue ORDER BY id DESC LIMIT ?');
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

echo json_encode(['items' => $items], JSON_ENCODE_FLAGS);
