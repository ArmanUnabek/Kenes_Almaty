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
}

