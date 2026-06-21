<?php
/**
 * Telegram Bot webhook endpoint.
 * Register via api/telegram_setup.php or:
 *   curl https://api.telegram.org/bot<TOKEN>/setWebhook \
 *     -d url=https://yoursite.com/api/telegram_webhook.php \
 *     -d secret_token=<TELEGRAM_WEBHOOK_SECRET>
 */

require_once __DIR__ . '/../config.php';

use App\Services\TelegramService;
use App\Middleware\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

// Validate Telegram's secret token header
$expectedSecret = defined('TELEGRAM_WEBHOOK_SECRET') ? TELEGRAM_WEBHOOK_SECRET : '';
$incomingSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

if ($expectedSecret === '' || $incomingSecret !== $expectedSecret) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$body   = file_get_contents('php://input');
$update = json_decode($body, true);

if (!is_array($update)) {
    echo json_encode(['ok' => true]);
    exit;
}

$message = $update['message'] ?? null;
if (!$message) {
    echo json_encode(['ok' => true]);
    exit;
}

$chatId   = (string)($message['chat']['id'] ?? '');
$text     = trim($message['text'] ?? '');
$fromId   = (string)($message['from']['id'] ?? '');

if ($chatId === '') {
    echo json_encode(['ok' => true]);
    exit;
}

// Per-chat rate limit: 30 messages per minute
if (!RateLimiter::check('tg_chat_' . $chatId, 30, 60)) {
    TelegramService::sendMessage($chatId, '⚠️ Слишком много запросов. Подождите немного.');
    echo json_encode(['ok' => true]);
    exit;
}

try {
    $db = getDBConnection();

    if ($text === '') {
        echo json_encode(['ok' => true]);
        exit;
    }

    // Parse command (strip @BotUsername suffix if present)
    $command = '';
    $args    = '';
    if (str_starts_with($text, '/')) {
        $parts   = explode(' ', $text, 2);
        $command = strtolower(explode('@', $parts[0])[0]);
        $args    = trim($parts[1] ?? '');
    }

    switch ($command) {
        case '/start':
            botStart($db, $chatId);
            break;
        case '/help':
            botHelp($chatId);
            break;
        case '/link':
            botLink($db, $chatId, $args);
            break;
        case '/login':
            botLogin($db, $chatId);
            break;
        case '/status':
            botStatus($db, $chatId);
            break;
        case '/letters':
            botLetters($db, $chatId);
            break;
        case '/myletters':
            botMyLetters($db, $chatId);
            break;
        default:
            if ($text !== '') {
                TelegramService::sendMessage($chatId, 'Неизвестная команда. Введите /help для списка доступных команд.');
            }
    }
} catch (\Throwable $e) {
    error_log('telegram_webhook error: ' . $e->getMessage());
}

echo json_encode(['ok' => true]);
exit;

// ─── Command Handlers ────────────────────────────────────────────────────────

function botStart(\PDO $db, string $chatId): void
{
    $user = TelegramService::findUserByChatId($db, $chatId);
    if ($user) {
        TelegramService::sendMessage($chatId,
            "👋 Привет, <b>" . htmlspecialchars($user['full_name'], ENT_QUOTES) . "</b>!\n\n" .
            "Вы вошли как <b>" . htmlspecialchars($user['username'], ENT_QUOTES) . "</b>.\n\n" .
            "Используйте /help для списка команд."
        );
    } else {
        $bot = defined('TELEGRAM_BOT_USERNAME') ? TELEGRAM_BOT_USERNAME : 'этот бот';
        TelegramService::sendMessage($chatId,
            "👋 Добро пожаловать в <b>Журнал Общественного Совета</b>!\n\n" .
            "Чтобы начать работу:\n" .
            "1. Войдите на сайт через браузер\n" .
            "2. Нажмите «Привязать Telegram» в меню профиля\n" .
            "3. Отправьте боту: <code>/link &lt;6-значный код&gt;</code>\n\n" .
            "После привязки вы сможете входить на сайт одним кликом через /login."
        );
    }
}

function botHelp(string $chatId): void
{
    TelegramService::sendMessage($chatId,
        "<b>Команды бота Журнала ОС</b>\n\n" .
        "/start — Приветствие и статус аккаунта\n" .
        "/link &lt;код&gt; — Привязать Telegram к аккаунту\n" .
        "/login — Войти на сайт по ссылке (без пароля)\n" .
        "/status — Сводка по дедлайнам писем\n" .
        "/letters — Список ближайших входящих писем с дедлайнами\n" .
        "/myletters — Письма, назначенные на вас\n" .
        "/help — Показать этот список"
    );
}

function botLink(\PDO $db, string $chatId, string $code): void
{
    if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
        TelegramService::sendMessage($chatId,
            "Использование: <code>/link 123456</code>\n" .
            "Получите 6-значный код в профиле на сайте."
        );
        return;
    }

    // Delete expired codes
    try {
        $db->exec("DELETE FROM telegram_link_codes WHERE expires_at < NOW()");
    } catch (\Throwable $e) {}

    $stmt = $db->prepare('SELECT * FROM telegram_link_codes WHERE code = ? AND used_at IS NULL');
    $stmt->execute([$code]);
    $row = $stmt->fetch();

    if (!$row) {
        TelegramService::sendMessage($chatId, '❌ Неверный или просроченный код. Получите новый код на сайте.');
        return;
    }

    // Check if this chat is already linked to another user
    $stmtChk = $db->prepare('SELECT id, username FROM users WHERE telegram_chat_id = ? AND id != ?');
    $stmtChk->execute([$chatId, $row['user_id']]);
    if ($stmtChk->fetch()) {
        TelegramService::sendMessage($chatId, '⚠️ Этот Telegram-аккаунт уже привязан к другому пользователю. Обратитесь к администратору.');
        return;
    }

    // Save chat ID and mark code as used
    $db->prepare('UPDATE users SET telegram_chat_id = ? WHERE id = ?')->execute([$chatId, $row['user_id']]);
    $db->prepare('UPDATE telegram_link_codes SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);

    $stmtUser = $db->prepare('SELECT full_name FROM users WHERE id = ?');
    $stmtUser->execute([$row['user_id']]);
    $user = $stmtUser->fetch();
    $name = $user['full_name'] ?? 'Пользователь';

    TelegramService::sendMessage($chatId,
        "✅ <b>Аккаунт успешно привязан!</b>\n\n" .
        "Привет, <b>" . htmlspecialchars($name, ENT_QUOTES) . "</b>!\n" .
        "Теперь вы можете:\n" .
        "• Войти на сайт одним кликом: /login\n" .
        "• Проверить дедлайны: /status\n" .
        "• Посмотреть письма: /letters"
    );
}

function botLogin(\PDO $db, string $chatId): void
{
    $user = TelegramService::findUserByChatId($db, $chatId);
    if (!$user) {
        TelegramService::sendMessage($chatId,
            "Сначала привяжите аккаунт.\nПолучите код на сайте и отправьте: /link &lt;код&gt;"
        );
        return;
    }

    // Generate single-use token (64 hex chars = 32 random bytes)
    $token     = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minutes

    // Invalidate previous unused tokens for this user
    try {
        $db->prepare('DELETE FROM telegram_login_tokens WHERE user_id = ? AND used_at IS NULL')->execute([$user['id']]);
    } catch (\Throwable $e) {}

    $db->prepare('INSERT INTO telegram_login_tokens (user_id, token, expires_at) VALUES (?, ?, ?)')
       ->execute([$user['id'], $token, $expiresAt]);

    $appUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $loginUrl = $appUrl . '/api/auth.php?action=tg_login&token=' . $token;

    TelegramService::sendWithInlineKeyboard($chatId,
        "🔑 Ссылка для входа действительна <b>5 минут</b>. Не передавайте её другим людям.",
        [['text' => '🔑 Войти в журнал', 'url' => $loginUrl]]
    );
}

function botStatus(\PDO $db, string $chatId): void
{
    $user = TelegramService::findUserByChatId($db, $chatId);
    if (!$user) {
        TelegramService::sendMessage($chatId,
            "Сначала привяжите аккаунт: /link &lt;код&gt;"
        );
        return;
    }

    $regionId = $user['region_id'] ? (int)$user['region_id'] : null;

    $today = date('Y-m-d');

    if ($regionId) {
        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN deadline_date < ? AND deleted_at IS NULL AND linked_outgoing_id IS NULL THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN deadline_date BETWEEN ? AND DATE_ADD(?, INTERVAL 3 DAY) AND deleted_at IS NULL AND linked_outgoing_id IS NULL THEN 1 ELSE 0 END) AS urgent,
                SUM(CASE WHEN deadline_date > DATE_ADD(?, INTERVAL 3 DAY) AND deleted_at IS NULL AND linked_outgoing_id IS NULL THEN 1 ELSE 0 END) AS pending
            FROM incoming_letters
            WHERE region_id = ?
        ");
        $stmt->execute([$today, $today, $today, $today, $regionId]);
    } else {
        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN deadline_date < ? AND deleted_at IS NULL AND linked_outgoing_id IS NULL THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN deadline_date BETWEEN ? AND DATE_ADD(?, INTERVAL 3 DAY) AND deleted_at IS NULL AND linked_outgoing_id IS NULL THEN 1 ELSE 0 END) AS urgent,
                SUM(CASE WHEN deadline_date > DATE_ADD(?, INTERVAL 3 DAY) AND deleted_at IS NULL AND linked_outgoing_id IS NULL THEN 1 ELSE 0 END) AS pending
            FROM incoming_letters
        ");
        $stmt->execute([$today, $today, $today, $today]);
    }

    $stats = $stmt->fetch();
    $overdue = (int)($stats['overdue'] ?? 0);
    $urgent  = (int)($stats['urgent']  ?? 0);
    $pending = (int)($stats['pending'] ?? 0);

    $lines = ["📊 <b>Сводка по дедлайнам — " . date('d.m.Y') . "</b>\n"];
    $lines[] = $overdue > 0 ? "🔴 Просрочено: <b>{$overdue}</b>" : "🟢 Просрочено: 0";
    $lines[] = $urgent  > 0 ? "🟡 Срок ≤3 дней: <b>{$urgent}</b>"  : "🟢 Срок ≤3 дней: 0";
    $lines[] = "⏳ На рассмотрении: <b>{$pending}</b>";

    TelegramService::sendMessage($chatId, implode("\n", $lines));
}

function botLetters(\PDO $db, string $chatId): void
{
    $user = TelegramService::findUserByChatId($db, $chatId);
    if (!$user) {
        TelegramService::sendMessage($chatId, "Сначала привяжите аккаунт: /link &lt;код&gt;");
        return;
    }

    $regionId = $user['region_id'] ? (int)$user['region_id'] : null;
    $today    = date('Y-m-d');

    if ($regionId) {
        $stmt = $db->prepare("
            SELECT seq, organization, deadline_date, date
            FROM incoming_letters
            WHERE region_id = ? AND deleted_at IS NULL AND linked_outgoing_id IS NULL
              AND deadline_date IS NOT NULL
            ORDER BY deadline_date ASC
            LIMIT 5
        ");
        $stmt->execute([$regionId]);
    } else {
        $stmt = $db->prepare("
            SELECT seq, organization, deadline_date, date
            FROM incoming_letters
            WHERE deleted_at IS NULL AND linked_outgoing_id IS NULL
              AND deadline_date IS NOT NULL
            ORDER BY deadline_date ASC
            LIMIT 5
        ");
        $stmt->execute([]);
    }

    $letters = $stmt->fetchAll();
    if (!$letters) {
        TelegramService::sendMessage($chatId, "✅ Нет входящих писем без ответа.");
        return;
    }

    $lines = ["📬 <b>Ближайшие дедлайны:</b>\n"];
    foreach ($letters as $l) {
        $deadline = $l['deadline_date'] ?? '—';
        $daysLeft = $deadline !== '—'
            ? (int)((strtotime($deadline) - strtotime($today)) / 86400)
            : null;

        $icon = '⏳';
        if ($daysLeft !== null) {
            if ($daysLeft < 0)  $icon = '🔴';
            elseif ($daysLeft <= 3) $icon = '🟡';
            else                $icon = '🟢';
        }

        $deadlineLabel = $deadline !== '—' ? date('d.m.Y', strtotime($deadline)) : '—';
        $org = htmlspecialchars(mb_substr($l['organization'] ?? '—', 0, 40), ENT_QUOTES);
        $lines[] = "{$icon} <b>Вх.{$l['seq']}</b> · {$org}\n   📅 Дедлайн: {$deadlineLabel}" .
                   ($daysLeft !== null ? " ({$daysLeft} дн.)" : '');
    }

    TelegramService::sendMessage($chatId, implode("\n", $lines));
}

function botMyLetters(\PDO $db, string $chatId): void
{
    $user = TelegramService::findUserByChatId($db, $chatId);
    if (!$user) {
        TelegramService::sendMessage($chatId, "Сначала привяжите аккаунт: /link &lt;код&gt;");
        return;
    }

    $today = date('Y-m-d');

    // Match by full_name of the linked user against os_members
    $stmt = $db->prepare("
        SELECT il.seq, il.organization, il.deadline_date
        FROM incoming_letters il
        JOIN letter_members lm ON lm.letter_type = 'incoming' AND lm.letter_id = il.id
        JOIN os_members m ON m.id = lm.member_id
        WHERE m.full_name = ?
          AND il.deleted_at IS NULL
          AND il.linked_outgoing_id IS NULL
          AND il.deadline_date IS NOT NULL
        ORDER BY il.deadline_date ASC
        LIMIT 5
    ");
    $stmt->execute([$user['full_name']]);
    $letters = $stmt->fetchAll();

    if (!$letters) {
        TelegramService::sendMessage($chatId, "✅ На вас нет писем без ответа.");
        return;
    }

    $lines = ["📌 <b>Мои письма без ответа:</b>\n"];
    foreach ($letters as $l) {
        $deadline  = $l['deadline_date'] ?? '—';
        $daysLeft  = $deadline !== '—'
            ? (int)((strtotime($deadline) - strtotime($today)) / 86400)
            : null;
        $icon      = '⏳';
        if ($daysLeft !== null) {
            if ($daysLeft < 0)      $icon = '🔴';
            elseif ($daysLeft <= 3) $icon = '🟡';
            else                    $icon = '🟢';
        }
        $deadlineLabel = $deadline !== '—' ? date('d.m.Y', strtotime($deadline)) : '—';
        $org   = htmlspecialchars(mb_substr($l['organization'] ?? '—', 0, 40), ENT_QUOTES);
        $lines[] = "{$icon} <b>Вх.{$l['seq']}</b> · {$org}\n   📅 {$deadlineLabel}" .
                   ($daysLeft !== null ? " ({$daysLeft} дн.)" : '');
    }

    TelegramService::sendMessage($chatId, implode("\n", $lines));
}
