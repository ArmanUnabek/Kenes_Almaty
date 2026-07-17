<?php

namespace App\Services;

use PDO;

class EmailService
{
    public static function enqueue(
        PDO $db,
        string $to,
        string $subject,
        string $bodyHtml,
        ?string $bodyText = null,
        ?string $inReplyTo = null,
        ?string $threadId = null
    ): string {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log('EmailService::enqueue skipped invalid address: ' . $to);
            return '';
        }
        $messageId = self::generateMessageId();
        $stmt = $db->prepare("
            INSERT INTO email_queue (recipient_email, subject, body_html, body_text, status, message_id, in_reply_to, thread_id)
            VALUES (?, ?, ?, ?, 'queued', ?, ?, ?)
        ");
        $stmt->execute([$to, $subject, $bodyHtml, $bodyText, $messageId, $inReplyTo, $threadId ?: $messageId]);
        return $messageId;
    }

    /**
     * Generate a RFC 2822 compliant Message-ID.
     * Format: <uuid@domain>
     */
    public static function generateMessageId(): string
    {
        $domain = defined('SMTP_FROM') ? substr(strrchr(SMTP_FROM, '@'), 1) : 'localhost';
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
        return '<' . $uuid . '@' . $domain . '>';
    }

    /**
     * Send email via PHPMailer when available, with fallback to raw SMTP socket.
     * PHPMailer handles TLS certificate verification, AUTH PLAIN/LOGIN, and proper
     * RFC compliance. The raw socket fallback is used when PHPMailer is not installed.
     */
    public static function sendSmtp(
        string $to,
        string $subject,
        string $bodyHtml,
        ?string $bodyText = null,
        ?string $messageId = null,
        ?string $inReplyTo = null
    ): bool {
        $host     = defined('SMTP_HOST') ? SMTP_HOST : '';
        $port     = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
        $user     = defined('SMTP_USER') ? SMTP_USER : '';
        $pass     = defined('SMTP_PASS') ? SMTP_PASS : '';
        $from     = defined('SMTP_FROM') ? SMTP_FROM : 'noreply@example.com';
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Журнал ОС';

        if (empty($host)) {
            error_log('EmailService::sendSmtp: SMTP_HOST not configured');
            return false;
        }

        // Use PHPMailer if available (preferred: proper TLS, DKIM-ready, RFC-compliant)
        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            return self::sendWithPhpMailer($to, $subject, $bodyHtml, $bodyText ?? strip_tags($bodyHtml),
                                           $host, $port, $user, $pass, $from, $fromName, $messageId, $inReplyTo);
        }

        return self::sendWithRawSocket($to, $subject, $bodyHtml, $bodyText, $host, $port, $user, $pass, $from, $fromName, $messageId, $inReplyTo);
    }

    private static function sendWithPhpMailer(
        string $to, string $subject, string $bodyHtml, string $bodyText,
        string $host, int $port, string $user, string $pass, string $from, string $fromName,
        ?string $messageId = null, ?string $inReplyTo = null
    ): bool {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'base64';

            if (!empty($user)) {
                $mail->SMTPAuth   = true;
                $mail->Username   = $user;
                $mail->Password   = $pass;
            }

            if ($port === 465) {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($port === 587) {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom($from, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body    = $bodyHtml;
            $mail->AltBody = $bodyText;
            $mail->isHTML(true);

            if ($messageId !== null) {
                $mail->MessageID = $messageId;
            }
            if ($inReplyTo !== null) {
                $mail->addCustomHeader('In-Reply-To', $inReplyTo);
                $mail->addCustomHeader('References', $inReplyTo);
            }

            return $mail->send();
        } catch (\Throwable $e) {
            error_log('EmailService PHPMailer: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Legacy raw socket SMTP (fallback when PHPMailer is not installed).
     */
    private static function sendWithRawSocket(
        string $to, string $subject, string $bodyHtml, ?string $bodyText,
        string $host, int $port, string $user, string $pass, string $from, string $fromName,
        ?string $messageId = null, ?string $inReplyTo = null
    ): bool {
        // Build multipart message
        $boundary = md5(uniqid((string)time(), true));
        $textPart = $bodyText ?? strip_tags($bodyHtml);

        $message  = "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($textPart)) . "\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($bodyHtml)) . "\r\n";
        $message .= "--{$boundary}--\r\n";

        $headers  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
        $headers .= "To: {$to}\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        if ($messageId !== null) {
            $headers .= "Message-ID: {$messageId}\r\n";
        }
        if ($inReplyTo !== null) {
            $headers .= "In-Reply-To: {$inReplyTo}\r\n";
            $headers .= "References: {$inReplyTo}\r\n";
        }
        $headers .= $message;

        try {
            $sslCtx = stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ]);

            if ($port === 465) {
                $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $sslCtx);
            } else {
                $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $sslCtx);
            }
            if (!$socket) {
                error_log("EmailService::sendSmtp: connect failed: {$errstr} ({$errno})");
                return false;
            }

            // Cap every blocking read: without this fgets() can hang forever if the
            // SMTP server accepts the connection but never replies, freezing the cron.
            stream_set_timeout($socket, 15);

            // Read server greeting (may be multi-line)
            if (!self::smtpReadGreeting($socket, '220')) {
                fclose($socket);
                return false;
            }

            $domain = gethostname() ?: 'localhost';

            // EHLO returns multi-line 250 response; smtpCmd drains all lines
            if (!self::smtpCmd($socket, "EHLO {$domain}", '250')) {
                fclose($socket);
                return false;
            }

            // STARTTLS for port 587
            if ($port === 587) {
                if (!self::smtpCmd($socket, 'STARTTLS', '220')) {
                    fclose($socket);
                    return false;
                }
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT, $sslCtx)) {
                    fclose($socket);
                    error_log('EmailService::sendSmtp: TLS negotiation failed');
                    return false;
                }
                // Re-EHLO after TLS
                if (!self::smtpCmd($socket, "EHLO {$domain}", '250')) {
                    fclose($socket);
                    return false;
                }
            }

            // AUTH LOGIN
            if (!empty($user)) {
                if (!self::smtpCmd($socket, 'AUTH LOGIN', '334')) {
                    fclose($socket);
                    return false;
                }
                if (!self::smtpCmd($socket, base64_encode($user), '334')) {
                    fclose($socket);
                    return false;
                }
                if (!self::smtpCmd($socket, base64_encode($pass), '235')) {
                    fclose($socket);
                    return false;
                }
            }

            if (!self::smtpCmd($socket, "MAIL FROM:<{$from}>", '250')) {
                fclose($socket);
                return false;
            }
            if (!self::smtpCmd($socket, "RCPT TO:<{$to}>", '250')) {
                fclose($socket);
                return false;
            }
            if (!self::smtpCmd($socket, 'DATA', '354')) {
                fclose($socket);
                return false;
            }

            fputs($socket, $headers . "\r\n.\r\n");
            $resp = fgets($socket, 512);
            if (strpos($resp, '250') !== 0) {
                fclose($socket);
                error_log('EmailService::sendSmtp: DATA response: ' . $resp);
                return false;
            }

            self::smtpCmd($socket, 'QUIT', '221');
            fclose($socket);
            return true;

        } catch (\Throwable $e) {
            error_log('EmailService::sendSmtp exception: ' . $e->getMessage());
            return false;
        }
    }

    /** Максимум попыток отправки одного письма до перевода в 'failed'. */
    private const MAX_ATTEMPTS = 3;

    /** Строки в 'processing' старше этого числа минут считаются зависшими (воркер упал). */
    private const STUCK_MINUTES = 15;

    /**
     * Process the email queue: send up to $batchSize queued emails.
     *
     * Защита от двойной отправки при параллельных прогонах — атомарный claim:
     * строка переводится 'queued' -> 'processing' одним UPDATE, и отправляет
     * только процесс, чей rowCount()==1. flock() оставлен как дешёвая защита от
     * лишней работы на одном хосте, но корректность обеспечивает именно claim в БД
     * (работает и между хостами/контейнерами, и на MySQL/MariaDB/sqlite).
     *
     * Returns ['sent' => N, 'failed' => N, 'skipped' => N].
     */
    public static function processQueue(PDO $db, int $batchSize = 10): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $batchSize = max(1, min(50, $batchSize));

        $nowExpr = self::nowExpr($db);

        // Exclusive file lock reduces contention between cron processes on the same host.
        $lockPath = sys_get_temp_dir() . '/os_journal_email_queue.lock';
        $lock = fopen($lockPath, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $result['skipped'] = -1; // -1 signals: another process holds the lock
            if ($lock) {
                fclose($lock);
            }
            return $result;
        }

        try {
            // 1) Восстановление зависших: строки, застрявшие в 'processing' после
            //    падения воркера, возвращаем в 'queued' (или 'failed', если исчерпаны попытки).
            self::recoverStuck($db);

            // 2) Кандидаты на отправку.
            $stmt = $db->prepare("
                SELECT id, recipient_email, subject, body_html, body_text, message_id, in_reply_to
                FROM email_queue
                WHERE status = 'queued' AND attempts < ?
                ORDER BY id ASC
                LIMIT ?
            ");
            $stmt->bindValue(1, self::MAX_ATTEMPTS, PDO::PARAM_INT);
            $stmt->bindValue(2, $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                return $result;
            }

            // Атомарный claim: только выигравший процесс переведёт 'queued' -> 'processing'.
            // attempts++ здесь же — упавший воркер, оставивший строку в 'processing',
            // всё равно израсходует попытку и не зациклит «ядовитое» письмо навсегда.
            $stmtClaim = $db->prepare("
                UPDATE email_queue
                SET status = 'processing', processing_at = {$nowExpr}, attempts = attempts + 1
                WHERE id = ? AND status = 'queued'
            ");
            $stmtSent = $db->prepare("
                UPDATE email_queue SET status = 'sent', sent_at = {$nowExpr}, processing_at = NULL, error = NULL WHERE id = ?
            ");
            $stmtFail = $db->prepare("
                UPDATE email_queue SET status = 'failed', processing_at = NULL, error = ? WHERE id = ?
            ");
            // Не последняя попытка — возвращаем в очередь для следующего прогона.
            $stmtRetry = $db->prepare("
                UPDATE email_queue SET status = 'queued', processing_at = NULL, error = ? WHERE id = ?
            ");

            foreach ($rows as $row) {
                $id = (int)$row['id'];

                // Проигравший гонку процесс получит rowCount()==0 и пропустит строку.
                $stmtClaim->execute([$id]);
                if ($stmtClaim->rowCount() !== 1) {
                    continue;
                }
                // attempts в выборке ещё старое значение; после claim попытка израсходована.
                $attemptsAfter = (int)($row['attempts'] ?? 0) + 1;

                try {
                    $ok = self::sendSmtp(
                        $row['recipient_email'],
                        $row['subject'],
                        $row['body_html'] ?? '',
                        $row['body_text'] ?? null,
                        $row['message_id'] ?? null,
                        $row['in_reply_to'] ?? null
                    );
                    if ($ok) {
                        $stmtSent->execute([$id]);
                        $result['sent']++;
                    } elseif ($attemptsAfter >= self::MAX_ATTEMPTS) {
                        $stmtFail->execute(['SMTP send returned false', $id]);
                        $result['failed']++;
                    } else {
                        $stmtRetry->execute(['SMTP send returned false', $id]);
                        $result['failed']++;
                    }
                } catch (\Throwable $e) {
                    if ($attemptsAfter >= self::MAX_ATTEMPTS) {
                        $stmtFail->execute([$e->getMessage(), $id]);
                    } else {
                        $stmtRetry->execute([$e->getMessage(), $id]);
                    }
                    $result['failed']++;
                    error_log("EmailService::processQueue id={$id} error: " . $e->getMessage());
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $result;
    }

    /**
     * Вернуть в очередь письма, зависшие в 'processing' дольше STUCK_MINUTES
     * (воркер упал между claim и завершением). Исчерпавшие попытки — в 'failed'.
     * Порог времени считаем в PHP и передаём параметром, чтобы не зависеть от
     * различий синтаксиса интервалов между MySQL и sqlite.
     */
    private static function recoverStuck(PDO $db): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::STUCK_MINUTES * 60);

        $db->prepare("
            UPDATE email_queue
            SET status = 'failed', processing_at = NULL, error = 'timed out in processing'
            WHERE status = 'processing' AND processing_at IS NOT NULL
              AND processing_at < ? AND attempts >= ?
        ")->execute([$cutoff, self::MAX_ATTEMPTS]);

        $db->prepare("
            UPDATE email_queue
            SET status = 'queued', processing_at = NULL
            WHERE status = 'processing' AND processing_at IS NOT NULL
              AND processing_at < ? AND attempts < ?
        ")->execute([$cutoff, self::MAX_ATTEMPTS]);
    }

    /** SQL-выражение текущего времени для активного драйвера (MySQL/pgsql vs sqlite). */
    private static function nowExpr(PDO $db): string
    {
        $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        return (stripos($driver, 'mysql') !== false || stripos($driver, 'pgsql') !== false)
            ? 'NOW()' : "datetime('now')";
    }

    /**
     * Send an SMTP command and drain all response lines (handles multi-line responses
     * like EHLO where the server sends 250-capability lines followed by a final 250 line).
     * Lines with a hyphen in position 3 (e.g. "250-AUTH LOGIN") are continuation lines.
     * The final line has a space in position 3 (e.g. "250 OK").
     */
    private static function smtpCmd($socket, string $cmd, string $expectedCode): bool
    {
        fputs($socket, $cmd . "\r\n");

        // Mask credentials in logs
        $logCmd = preg_match('/^(AUTH LOGIN|AUTH PLAIN)/i', $cmd) ? preg_replace('/\s+.*/', ' ***', $cmd) : $cmd;

        do {
            $line = fgets($socket, 512);
            if ($line === false) {
                error_log("EmailService SMTP cmd '{$logCmd}': connection closed unexpectedly");
                return false;
            }
            // position 3 is '-' for continuation lines, ' ' for the last line
            $isContinued = isset($line[3]) && $line[3] === '-';
        } while ($isContinued);

        if (strpos($line, $expectedCode) !== 0) {
            error_log("EmailService SMTP cmd '{$logCmd}' expected {$expectedCode}, got: " . trim($line));
            return false;
        }
        return true;
    }

    /**
     * Read the initial SMTP greeting (may be multi-line on some servers).
     */
    private static function smtpReadGreeting($socket, string $expectedCode): bool
    {
        do {
            $line = fgets($socket, 512);
            if ($line === false) {
                error_log('EmailService::sendSmtp: connection closed during greeting');
                return false;
            }
            $isContinued = isset($line[3]) && $line[3] === '-';
        } while ($isContinued);

        if (strpos($line, $expectedCode) !== 0) {
            error_log('EmailService::sendSmtp: bad greeting: ' . trim($line));
            return false;
        }
        return true;
    }
}
