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

    public static function isConfigured(): bool
    {
        return self::botToken() !== '';
    }
}
