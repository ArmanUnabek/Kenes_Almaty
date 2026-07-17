<?php

namespace App\Services;

class SmsService
{
    public static function isConfigured(): bool
    {
        $enabled = filter_var(envValue('SMS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
        return $enabled && (envValue('MOBIZON_API_KEY') !== null && envValue('MOBIZON_API_KEY') !== '');
    }

    /**
     * Send an SMS immediately via Mobizon API.
     * Returns ['success' => bool, 'message_id' => string|null, 'error' => string|null].
     */
    public static function send(string $phone, string $text): array
    {
        if (!self::isConfigured()) {
            return ['success' => false, 'message_id' => null, 'error' => 'SMS not configured'];
        }

        $apiKey = envValue('MOBIZON_API_KEY') ?? '';
        $sender = envValue('MOBIZON_SENDER', 'InfoSMS') ?? 'InfoSMS';

        $phone = preg_replace('/\D/', '', $phone);
        if (strlen($phone) < 10) {
            return ['success' => false, 'message_id' => null, 'error' => 'Invalid phone number'];
        }

        $params = http_build_query([
            'apiKey'    => $apiKey,
            'recipient' => $phone,
            'text'      => $text,
            'sender'    => $sender,
            'output'    => 'json',
            'api'       => '1',
        ]);

        $url = 'https://api.mobizon.kz/service/message/sendSmsMessage?' . $params;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $context = stream_context_create([
                'http' => [
                    'method'        => 'GET',
                    'timeout'       => 10,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $resp = @file_get_contents($url, false, $context);

            if ($resp === false) {
                error_log("SmsService: connection failed (attempt {$attempt}/3)");
                if ($attempt < 3) {
                    usleep((int)(200000 * pow(2, $attempt - 1)));
                    continue;
                }
                return ['success' => false, 'message_id' => null, 'error' => 'Connection failed'];
            }

            $json = json_decode($resp, true);
            if (!is_array($json)) {
                return ['success' => false, 'message_id' => null, 'error' => 'Invalid API response'];
            }

            $code = (int)($json['code'] ?? -1);
            if ($code !== 0) {
                $errMsg = $json['message'] ?? "API error code {$code}";
                error_log("SmsService: API error {$code}: {$errMsg}");
                // Retry on 5xx-like errors
                if (in_array($code, [500, 503], true) && $attempt < 3) {
                    usleep((int)(200000 * pow(2, $attempt - 1)));
                    continue;
                }
                return ['success' => false, 'message_id' => null, 'error' => $errMsg];
            }

            $messageId = (string)($json['data']['messageId'] ?? '');
            return ['success' => true, 'message_id' => $messageId, 'error' => null];
        }

        return ['success' => false, 'message_id' => null, 'error' => 'Max retries exceeded'];
    }

    /**
     * Enqueue an SMS for batch delivery (adds to sms_queue table).
     */
    public static function enqueue(\PDO $db, string $phone, string $message, ?int $userId = null): int
    {
        $phone = preg_replace('/\D/', '', $phone);
        if ($phone === '') {
            return 0;
        }

        $stmt = $db->prepare("
            INSERT INTO sms_queue (user_id, phone, message, status)
            VALUES (?, ?, ?, 'queued')
        ");
        $stmt->execute([$userId, $phone, $message]);
        return (int)$db->lastInsertId();
    }

    /**
     * Process the SMS queue: attempt to send up to $batchSize queued messages.
     * Returns ['sent' => N, 'failed' => N, 'skipped' => N].
     */
    public static function processQueue(\PDO $db, int $batchSize = 20): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $batchSize = max(1, min(50, $batchSize));

        $nowExpr = self::nowExpr($db);

        // flock снижает конкуренцию на одном хосте; корректность (нет двойной отправки
        // при параллельных прогонах, в т.ч. между хостами) обеспечивает атомарный
        // claim через перевод строки 'queued' -> 'processing' с проверкой rowCount().
        $lockPath = sys_get_temp_dir() . '/os_journal_sms_queue.lock';
        $lock = fopen($lockPath, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $result['skipped'] = -1;
            if ($lock) fclose($lock);
            return $result;
        }

        try {
            self::recoverStuck($db);

            $stmt = $db->prepare("
                SELECT id, user_id, phone, message, attempts
                FROM sms_queue
                WHERE status = 'queued' AND attempts < 3
                ORDER BY id ASC
                LIMIT ?
            ");
            $stmt->bindValue(1, $batchSize, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                return $result;
            }

            // Атомарный claim: attempts++ выполняется здесь, поэтому UPDATE-ы отправки
            // ниже его уже не трогают.
            $stmtClaim = $db->prepare("
                UPDATE sms_queue
                SET status = 'processing', processing_at = {$nowExpr}, attempts = attempts + 1
                WHERE id = ? AND status = 'queued'
            ");
            $stmtSent = $db->prepare("
                UPDATE sms_queue SET status = 'sent', sent_at = {$nowExpr}, processing_at = NULL, error = NULL
                WHERE id = ?
            ");
            $stmtFail = $db->prepare("
                UPDATE sms_queue SET status = 'failed', processing_at = NULL, error = ?
                WHERE id = ?
            ");
            // Не последняя попытка — возвращаем в очередь.
            $stmtRetry = $db->prepare("
                UPDATE sms_queue SET status = 'queued', processing_at = NULL, error = ?
                WHERE id = ?
            ");

            foreach ($rows as $row) {
                $id = (int)$row['id'];

                $stmtClaim->execute([$id]);
                if ($stmtClaim->rowCount() !== 1) {
                    continue; // строку захватил другой процесс
                }
                $attemptsAfter = (int)$row['attempts'] + 1;

                $sendResult = self::send((string)$row['phone'], (string)$row['message']);

                if ($sendResult['success']) {
                    $stmtSent->execute([$id]);
                    $result['sent']++;
                } else {
                    $err = $sendResult['error'] ?? 'Unknown error';
                    // Попытки исчерпаны -> failed, иначе назад в очередь на повтор.
                    if ($attemptsAfter >= 3) {
                        $stmtFail->execute([$err, $id]);
                    } else {
                        $stmtRetry->execute([$err, $id]);
                    }
                    $result['failed']++;
                    error_log("SmsService::processQueue id={$id} error: {$err}");
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $result;
    }

    /**
     * Вернуть в очередь SMS, зависшие в 'processing' дольше 15 минут (воркер упал).
     * Исчерпавшие попытки — в 'failed'. Порог считаем в PHP ради переносимости
     * между MySQL и sqlite.
     */
    private static function recoverStuck(\PDO $db): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - 15 * 60);

        $db->prepare("
            UPDATE sms_queue
            SET status = 'failed', processing_at = NULL, error = 'timed out in processing'
            WHERE status = 'processing' AND processing_at IS NOT NULL
              AND processing_at < ? AND attempts >= 3
        ")->execute([$cutoff]);

        $db->prepare("
            UPDATE sms_queue
            SET status = 'queued', processing_at = NULL
            WHERE status = 'processing' AND processing_at IS NOT NULL
              AND processing_at < ? AND attempts < 3
        ")->execute([$cutoff]);
    }

    /** SQL-выражение текущего времени для активного драйвера. */
    private static function nowExpr(\PDO $db): string
    {
        $driver = (string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        return (stripos($driver, 'mysql') !== false || stripos($driver, 'pgsql') !== false)
            ? 'NOW()' : "datetime('now')";
    }

    /**
     * Send SMS to all users assigned to a letter who have sms_phone set.
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

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmtU = $db->prepare(
            "SELECT u.id, u.sms_phone FROM users u
             WHERE u.member_id IN ({$placeholders})
               AND u.sms_phone IS NOT NULL AND u.sms_phone != ''
               AND u.is_active = TRUE"
        );
        $stmtU->execute($memberIds);
        $users = $stmtU->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($users)) {
            return;
        }

        $typeLabel = $type === 'incoming' ? 'Вх.' : 'Исх.';
        $orgShort  = mb_substr($org, 0, 40);
        $text = "Журнал ОС: Вас назначили на {$typeLabel}{$seq} ({$orgShort})";

        foreach ($users as $u) {
            try {
                self::enqueue($db, (string)$u['sms_phone'], $text, (int)$u['id']);
            } catch (\Throwable $e) {
                error_log('SmsService::notifyLetterAssignment: ' . $e->getMessage());
            }
        }
    }
}
