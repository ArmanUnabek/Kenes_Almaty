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
}
