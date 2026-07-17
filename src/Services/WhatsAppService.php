<?php

namespace App\Services;

class WhatsAppService
{
    private static function token(): string
    {
        return defined('WHATSAPP_TOKEN') ? WHATSAPP_TOKEN : (getenv('WHATSAPP_TOKEN') ?: '');
    }

    private static function phoneId(): string
    {
        return defined('WHATSAPP_PHONE_ID') ? WHATSAPP_PHONE_ID : (getenv('WHATSAPP_PHONE_ID') ?: '');
    }

    public static function isConfigured(): bool
    {
        return self::token() !== '' && self::phoneId() !== '';
    }

    public static function sendMessage(string $phone, string $message): bool
    {
        if (!self::isConfigured() || $phone === '') {
            return false;
        }

        $phone = self::normalizePhone($phone);
        $url  = 'https://graph.facebook.com/v18.0/' . self::phoneId() . '/messages';
        $body = json_encode([
            'messaging_product' => 'whatsapp',
            'to'   => $phone,
            'type' => 'text',
            'text' => ['body' => $message],
        ]);

        return self::post($url, $body);
    }

    public static function sendTemplateMessage(
        string $phone,
        string $templateName,
        array $params = [],
        string $langCode = 'ru'
    ): bool {
        if (!self::isConfigured() || $phone === '') {
            return false;
        }

        $phone = self::normalizePhone($phone);
        $url  = 'https://graph.facebook.com/v18.0/' . self::phoneId() . '/messages';

        $components = [];
        if (!empty($params)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(fn($p) => [
                    'type' => 'text',
                    'text' => (string)$p,
                ], $params),
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'       => $phone,
            'type'     => 'template',
            'template' => [
                'name'     => ['namespace' => '', 'name' => $templateName],
                'language' => ['code' => $langCode],
            ],
        ];
        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        return self::post($url, json_encode($payload));
    }

    public static function notifyDeadline(
        string $phone,
        string $letterNumber,
        string $deadlineDate
    ): bool {
        $message = "📋 Напоминание о сроке\n\n" .
                   "Письмо: {$letterNumber}\n" .
                   "Крайний срок: {$deadlineDate}\n\n" .
                   "Пожалуйста, подготовьте ответ до указанной даты.";

        return self::sendMessage($phone, $message);
    }

    private static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', $phone);
        if ($phone !== '' && $phone[0] !== '+') {
            $phone = '+' . $phone;
        }
        return $phone;
    }

    private static function post(string $url, string $body): bool
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $context = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/json\r\n" .
                                       "Authorization: Bearer " . self::token() . "\r\n" .
                                       "Content-Length: " . strlen($body) . "\r\n",
                    'content'       => $body,
                    'timeout'       => 10,
                    'ignore_errors' => true,
                ],
            ]);

            $resp = @file_get_contents($url, false, $context);
            if ($resp === false) {
                error_log("WhatsAppService: connection failed (attempt {$attempt}/3)");
                if ($attempt < 3) {
                    usleep((int)(200000 * pow(2, $attempt - 1)));
                    continue;
                }
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

            if ($httpCode >= 200 && $httpCode < 300) {
                return true;
            }

            $json = json_decode($resp, true);
            $errMsg = $json['error']['message'] ?? $resp;

            if (($httpCode === 429 || $httpCode >= 500) && $attempt < 3) {
                error_log("WhatsAppService: retryable error {$httpCode} (attempt {$attempt}/3): {$errMsg}");
                usleep((int)(200000 * pow(2, $attempt - 1)));
                continue;
            }

            error_log("WhatsAppService: API error {$httpCode}: {$errMsg}");
            return false;
        }

        return false;
    }
}
