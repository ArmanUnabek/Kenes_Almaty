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
        $host    = defined('SMTP_HOST') ? SMTP_HOST : '';
        $port    = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
        $user    = defined('SMTP_USER') ? SMTP_USER : '';
        $pass    = defined('SMTP_PASS') ? SMTP_PASS : '';
        $from    = defined('SMTP_FROM') ? SMTP_FROM : 'noreply@example.com';
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
            // Determine connection type
            if ($port === 465) {
                $socket = @fsockopen("ssl://{$host}", $port, $errno, $errstr, 15);
            } else {
                $socket = @fsockopen($host, $port, $errno, $errstr, 15);
            }
            if (!$socket) {
                error_log("EmailService::sendSmtp: connect failed: {$errstr} ({$errno})");
                return false;
            }

            $read = fgets($socket, 512);
            if (strpos($read, '220') !== 0) {
                fclose($socket);
                error_log('EmailService::sendSmtp: bad greeting: ' . $read);
                return false;
            }

            $domain = gethostname() ?: 'localhost';
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
            if (!self::smtpCmd($socket, "RCPT TO:<{$to}>", '25')) {
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
     * Returns ['sent' => N, 'failed' => N, 'skipped' => N].
     */
    public static function processQueue(PDO $db, int $batchSize = 10): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

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

        return $result;
    }

    private static function smtpCmd($socket, string $cmd, string $expectedCode): bool
    {
        fputs($socket, $cmd . "\r\n");
        $resp = fgets($socket, 512);
        if (strpos($resp, $expectedCode) !== 0) {
            error_log("EmailService SMTP cmd '{$cmd}' expected {$expectedCode}, got: " . trim($resp));
            return false;
        }
        return true;
    }
}
