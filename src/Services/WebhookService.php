<?php

namespace App\Services;

use PDO;

class WebhookService
{
    public static function dispatch(PDO $db, string $event, array $payload): void
    {
        try {
            $stmt = $db->prepare(
                "SELECT id, url, secret FROM webhooks WHERE is_active = 1 AND JSON_CONTAINS(events, ?)"
            );
            $stmt->execute([json_encode($event)]);
            $hooks = $stmt->fetchAll();

            foreach ($hooks as $hook) {
                self::deliverOne($db, $hook, $event, $payload);
            }
        } catch (\Throwable $e) {
            error_log('WebhookService::dispatch error: ' . $e->getMessage());
        }
    }

    public static function verifySignature(string $body, string $signature, string $secret): bool
    {
        $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, $signature);
    }

    public static function testDispatch(PDO $db, int $webhookId): array
    {
        $stmt = $db->prepare("SELECT id, url, secret FROM webhooks WHERE id = ? AND is_active = 1");
        $stmt->execute([$webhookId]);
        $hook = $stmt->fetch();
        if (!$hook) {
            return ['success' => false, 'error' => 'Webhook не найден или неактивен'];
        }

        $testPayload = ['test' => true, 'message' => 'This is a test webhook delivery'];
        self::deliverOne($db, $hook, 'webhook.test', $testPayload);

        $deliveryStmt = $db->prepare("SELECT id, response_code, response_body, delivered_at FROM webhook_deliveries WHERE webhook_id = ? ORDER BY id DESC LIMIT 1");
        $deliveryStmt->execute([$webhookId]);
        $lastDelivery = $deliveryStmt->fetch();

        return [
            'success' => true,
            'delivery' => $lastDelivery,
        ];
    }

    public static function logDelivery(PDO $db, int $webhookId, string $event, array $payload, ?int $status, ?string $response): int
    {
        $stmt = $db->prepare(
            "INSERT INTO webhook_deliveries (webhook_id, event, payload, response_code, response_body, delivered_at) VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $webhookId,
            $event,
            json_encode($payload, JSON_ENCODE_FLAGS),
            $status,
            $response ? substr($response, 0, 2000) : null,
        ]);
        return (int)$db->lastInsertId();
    }

    private static function deliverOne(PDO $db, array $hook, string $event, array $payload): void
    {
        $body = json_encode([
            'event'     => $event,
            'timestamp' => date('c'),
            'data'      => $payload,
        ], JSON_ENCODE_FLAGS);

        $sig = 'sha256=' . hash_hmac('sha256', $body, $hook['secret']);

        $deliveryId = null;
        try {
            $ins = $db->prepare(
                "INSERT INTO webhook_deliveries (webhook_id, event, payload) VALUES (?, ?, ?)"
            );
            $ins->execute([$hook['id'], $event, json_encode($payload, JSON_ENCODE_FLAGS)]);
            $deliveryId = (int)$db->lastInsertId();

            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nX-Hub-Signature-256: {$sig}\r\nX-Event: {$event}\r\nUser-Agent: OsJournal-Webhook/1.0",
                'content'       => $body,
                'timeout'       => 10,
                'ignore_errors' => true,
            ]]);

            $response     = @file_get_contents($hook['url'], false, $ctx);
            $responseCode = null;
            if (isset($http_response_header)) {
                foreach ($http_response_header as $h) {
                    if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) {
                        $responseCode = (int)$m[1];
                    }
                }
            }

            $db->prepare(
                "UPDATE webhook_deliveries SET response_code = ?, response_body = ?, delivered_at = NOW() WHERE id = ?"
            )->execute([
                $responseCode,
                is_string($response) ? substr($response, 0, 2000) : null,
                $deliveryId,
            ]);
        } catch (\Throwable $e) {
            error_log("Webhook delivery failed for hook #{$hook['id']}: " . $e->getMessage());
            if ($deliveryId) {
                try {
                    $db->prepare(
                        "UPDATE webhook_deliveries SET response_body = ? WHERE id = ?"
                    )->execute([substr($e->getMessage(), 0, 500), $deliveryId]);
                } catch (\Throwable) {}
            }
        }
    }
}
