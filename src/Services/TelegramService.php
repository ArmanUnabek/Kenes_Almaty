<?php

namespace App\Services;

class TelegramService
{
    private static function botToken(): string
    {
        return defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : (getenv('TELEGRAM_BOT_TOKEN') ?: '');
    }

    private static function makeApiRequest(string $endpoint, string $body): bool
    {
        $token = self::botToken();
        $url   = "https://api.telegram.org/bot{$token}/{$endpoint}";

        for ($attempt = 1; $attempt <= 3; $attempt++) {
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
                error_log("TelegramService: failed to connect (attempt {$attempt}/3)");
                if ($attempt < 3) { usleep((int)(100000 * pow(2, $attempt - 1))); continue; }
                return false;
            }

            $httpCode = 0;
            if (isset($http_response_header)) {
                foreach ($http_response_header as $h) {
                    if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $h, $m)) {
                        $httpCode = (int)$m[1];
                    }
                }
            }

            $json = json_decode($resp, true);
            if (!($json['ok'] ?? false)) {
                $desc = $json['description'] ?? $resp;
                if (($httpCode === 429 || $httpCode >= 500) && $attempt < 3) {
                    $retryAfter = ($json['parameters']['retry_after'] ?? 1) * 1000000;
                    error_log("TelegramService: retryable error {$httpCode} (attempt {$attempt}/3): {$desc}");
                    usleep(min($retryAfter, (int)(1000000 * pow(2, $attempt - 1))));
                    continue;
                }
                error_log("TelegramService: API error {$httpCode}: {$desc}");
                return false;
            }

            return true;
        }

        return false;
    }

    public static function sendMessage(string $chatId, string $text): bool
    {
        if (self::botToken() === '' || $chatId === '') {
            return false;
        }
        return self::makeApiRequest('sendMessage', (string)json_encode([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]));
    }

    public static function sendWithInlineKeyboard(string $chatId, string $text, array $buttons): bool
    {
        if (self::botToken() === '' || $chatId === '') {
            return false;
        }
        $keyboard = ['inline_keyboard' => [
            array_map(fn($b) => ['text' => $b['text'], 'url' => $b['url']], $buttons),
        ]];
        return self::makeApiRequest('sendMessage', (string)json_encode([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboard,
        ]));
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
    /**
     * Notify all active users with a Telegram account about a new event.
     */
    public static function notifyNewEvent(
        \PDO $db,
        int $eventId,
        string $title,
        string $eventDate,
        ?string $location
    ): void {
        if (!self::isConfigured()) {
            return;
        }

        $stmt = $db->prepare(
            'SELECT telegram_chat_id FROM users WHERE telegram_chat_id IS NOT NULL AND is_active = TRUE'
        );
        $stmt->execute();
        $chatIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($chatIds)) {
            return;
        }

        $dateLabel  = date('d.m.Y', strtotime($eventDate));
        $locLabel   = $location ? "\n📍 " . htmlspecialchars(mb_substr($location, 0, 100), ENT_QUOTES) : '';
        $appUrl     = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        $link       = $appUrl ? "\n<a href=\"{$appUrl}/api/\">Открыть журнал</a>" : '';
        $titleShort = mb_substr($title, 0, 80);
        $text = "📅 <b>Новое мероприятие</b>\n\n" .
                "<b>" . htmlspecialchars($titleShort, ENT_QUOTES) . "</b>\n" .
                "🗓 {$dateLabel}{$locLabel}{$link}";

        foreach ($chatIds as $chatId) {
            try {
                self::sendMessage((string)$chatId, $text);
            } catch (\Throwable $e) {
                error_log('TelegramService::notifyNewEvent failed for chat ' . $chatId . ': ' . $e->getMessage());
            }
        }
    }

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

        // Find users linked to these members via member_id
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmtU = $db->prepare(
            "SELECT u.full_name, u.telegram_chat_id FROM users u WHERE u.member_id IN ({$placeholders}) AND u.telegram_chat_id IS NOT NULL AND u.is_active = TRUE"
        );
        $stmtU->execute($memberIds);
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
