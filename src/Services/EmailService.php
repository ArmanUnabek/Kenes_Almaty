<?php

namespace App\Services;

use PDO;

class EmailService
{
    public static function enqueue(PDO $db, string $to, string $subject, string $bodyHtml, ?string $bodyText = null): void
    {
        $stmt = $db->prepare("
            INSERT INTO email_queue (recipient_email, subject, body_html, body_text, status)
            VALUES (?, ?, ?, ?, 'queued')
        ");
        $stmt->execute([$to, $subject, $bodyHtml, $bodyText]);
    }

    /**
     * Send email via SMTP using raw socket (no external library needed).
     * Supports STARTTLS on port 587, plain SMTP on port 25, SSL on port 465.
     */
    public static function sendSmtp(
        string $to,
        string $subject,
        string $bodyHtml,
        ?string $bodyText = null
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
        $headers .= $message;

        try {
            if ($port === 465) {
                $socket = @fsockopen("ssl://{$host}", $port, $errno, $errstr, 15);
            } else {
                $socket = @fsockopen($host, $port, $errno, $errstr, 15);
            }
            if (!$socket) {
                error_log("EmailService::sendSmtp: connect failed: {$errstr} ({$errno})");
                return false;
            }

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
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
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

    /**
     * Process the email queue: send up to $batchSize queued emails.
     * Uses a file lock to prevent parallel runs from double-sending.
     * Returns ['sent' => N, 'failed' => N, 'skipped' => N].
     */
    public static function processQueue(PDO $db, int $batchSize = 10): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        // Exclusive file lock prevents two cron processes from claiming the same rows
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
            $stmt = $db->prepare("
                SELECT id, recipient_email, subject, body_html, body_text
                FROM email_queue
                WHERE status = 'queued'
                ORDER BY id ASC
                LIMIT ?
            ");
            $stmt->bindValue(1, $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                return $result;
            }

            $stmtSent = $db->prepare("
                UPDATE email_queue SET status = 'sent', sent_at = NOW(), error = NULL WHERE id = ?
            ");
            $stmtFail = $db->prepare("
                UPDATE email_queue SET status = 'failed', error = ? WHERE id = ?
            ");

            foreach ($rows as $row) {
                $id = (int)$row['id'];
                try {
                    $ok = self::sendSmtp(
                        $row['recipient_email'],
                        $row['subject'],
                        $row['body_html'] ?? '',
                        $row['body_text'] ?? null
                    );
                    if ($ok) {
                        $stmtSent->execute([$id]);
                        $result['sent']++;
                    } else {
                        $stmtFail->execute(['SMTP send returned false', $id]);
                        $result['failed']++;
                    }
                } catch (\Throwable $e) {
                    $stmtFail->execute([$e->getMessage(), $id]);
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
     * Send an SMTP command and drain all response lines (handles multi-line responses
     * like EHLO where the server sends 250-capability lines followed by a final 250 line).
     * Lines with a hyphen in position 3 (e.g. "250-AUTH LOGIN") are continuation lines.
     * The final line has a space in position 3 (e.g. "250 OK").
     */
    private static function smtpCmd($socket, string $cmd, string $expectedCode): bool
    {
        fputs($socket, $cmd . "\r\n");

        do {
            $line = fgets($socket, 512);
            if ($line === false) {
                error_log("EmailService SMTP cmd '{$cmd}': connection closed unexpectedly");
                return false;
            }
            // position 3 is '-' for continuation lines, ' ' for the last line
            $isContinued = isset($line[3]) && $line[3] === '-';
        } while ($isContinued);

        if (strpos($line, $expectedCode) !== 0) {
            error_log("EmailService SMTP cmd '{$cmd}' expected {$expectedCode}, got: " . trim($line));
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
