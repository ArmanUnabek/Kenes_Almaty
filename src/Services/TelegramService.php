<?php

namespace App\Services;

class TelegramService
{
    private static function botToken(): string
    {
        return defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : (getenv('TELEGRAM_BOT_TOKEN') ?: '');
    }

    public static function sendMessage(string $chatId, string $text): bool
    {
        $token = self::botToken();
        if ($token === '' || $chatId === '') {
            return false;
        }

        $url  = "https://api.telegram.org/bot{$token}/sendMessage";
        $body = json_encode([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n",
                'content'       => $body,
                'timeout'       => 5,
                'ignore_errors' => true,
            ],
        ]);

        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) {
            error_log('TelegramService: failed to connect');
            return false;
        }

        $json = json_decode($resp, true);
        if (!($json['ok'] ?? false)) {
            error_log('TelegramService: API error: ' . ($json['description'] ?? $resp));
            return false;
        }

        return true;
    }

    public static function sendWithInlineKeyboard(string $chatId, string $text, array $buttons): bool
    {
        $token = self::botToken();
        if ($token === '' || $chatId === '') {
            return false;
        }

        $keyboard = ['inline_keyboard' => [
            array_map(fn($b) => ['text' => $b['text'], 'url' => $b['url']], $buttons)
        ]];

        $url  = "https://api.telegram.org/bot{$token}/sendMessage";
        $body = json_encode([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboard,
        ]);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n",
                'content'       => $body,
                'timeout'       => 5,
                'ignore_errors' => true,
            ],
        ]);

        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) {
            error_log('TelegramService: failed to connect (sendWithInlineKeyboard)');
            return false;
        }

        $json = json_decode($resp, true);
        if (!($json['ok'] ?? false)) {
            error_log('TelegramService: API error: ' . ($json['description'] ?? $resp));
            return false;
        }

        return true;
    }

    public static function findUserByChatId(\PDO $db, string $chatId): ?array
    {
        if ($chatId === '') {
            return null;
        }
        $stmt = $db->prepare(
            'SELECT id, username, full_name, role, region_id FROM users WHERE telegram_chat_id = ? AND is_active = TRUE'
        );
        $stmt->execute([$chatId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function isConfigured(): bool
    {
        return self::botToken() !== '';
    }

    /**
     * Notify users who have a linked Telegram account that they were assigned to a letter.
     *
     * @param \PDO   $db        Active DB connection
     * @param array  $memberIds List of os_members.id that were newly assigned
     * @param string $type      'incoming' or 'outgoing'
     * @param int    $seq       Letter sequence number (for display)
     * @param string $org       Organization / subject of the letter
     */
    public static function notifyLetterAssignment(
        \PDO $db,
        array $memberIds,
        string $type,
        int $seq,
        string $org
    ): void {
        if (empty($memberIds) || !self::isConfigured()) {
            return;
        }

        // Get full_name for each member, then find linked users
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmtM = $db->prepare("SELECT id, full_name FROM os_members WHERE id IN ({$placeholders})");
        $stmtM->execute($memberIds);
        $members = $stmtM->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($members)) {
            return;
        }

        $namePlaceholders = implode(',', array_fill(0, count($members), '?'));
        $names = array_column($members, 'full_name');
        $stmtU = $db->prepare(
            "SELECT full_name, telegram_chat_id FROM users WHERE full_name IN ({$namePlaceholders}) AND telegram_chat_id IS NOT NULL AND is_active = TRUE"
        );
        $stmtU->execute($names);
        $users = $stmtU->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($users)) {
            return;
        }

        $typeLabel = $type === 'incoming' ? 'Вх.' : 'Исх.';
        $orgShort  = mb_substr($org, 0, 60);
        $appUrl    = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        $link      = $appUrl ? $appUrl . '/api/' : '';

        foreach ($users as $u) {
            $chatId = $u['telegram_chat_id'];
            $userName = htmlspecialchars($u['full_name'] ?? '', ENT_QUOTES);
            $text  = "📋 <b>Вас назначили на письмо</b>\n\n" .
                     "{$typeLabel}<b>{$seq}</b> · " . htmlspecialchars($orgShort, ENT_QUOTES) . "\n\n" .
                     "Здравствуйте, <b>{$userName}</b>! Вы добавлены как исполнитель.";
            if ($link) {
                $text .= "\n<a href=\"{$link}\">Открыть журнал</a>";
            }
            try {
                self::sendMessage((string)$chatId, $text);
            } catch (\Throwable $e) {
                error_log('TelegramService::notifyLetterAssignment failed for chat ' . $chatId . ': ' . $e->getMessage());
            }
        }
    }
}
