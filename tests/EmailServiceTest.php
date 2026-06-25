<?php

namespace Tests;

use App\Services\EmailService;
use Tests\Support\RepositoryTestCase;

/**
 * Unit-tests the DB-backed surface of App\Services\EmailService: enqueue() (row
 * insertion) and processQueue()'s empty-queue path against in-memory SQLite. The
 * SMTP send path (sendSmtp / raw sockets / PHPMailer) is out of scope — it needs
 * a live mail server and would require transport injection to test.
 */
class EmailServiceTest extends RepositoryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->exec("CREATE TABLE email_queue (
            id INTEGER PRIMARY KEY,
            recipient_email TEXT,
            subject TEXT,
            body_html TEXT,
            body_text TEXT,
            status TEXT DEFAULT 'queued',
            error TEXT,
            sent_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function testEnqueueInsertsQueuedRow(): void
    {
        EmailService::enqueue($this->db, 'to@example.com', 'Тема', '<b>Тело</b>', 'Тело');

        $row = $this->db->query('SELECT * FROM email_queue ORDER BY id DESC LIMIT 1')
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('to@example.com', $row['recipient_email']);
        $this->assertSame('Тема', $row['subject']);
        $this->assertSame('<b>Тело</b>', $row['body_html']);
        $this->assertSame('Тело', $row['body_text']);
        $this->assertSame('queued', $row['status']);
    }

    public function testEnqueueAllowsNullBodyText(): void
    {
        EmailService::enqueue($this->db, 'x@example.com', 'S', '<p>H</p>');
        $row = $this->db->query('SELECT body_text FROM email_queue ORDER BY id DESC LIMIT 1')
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['body_text']);
    }

    public function testProcessQueueEmptyReturnsZeroCounts(): void
    {
        // No queued rows → returns immediately without attempting any SMTP send.
        $result = EmailService::processQueue($this->db, 10);
        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 0], $result);
    }
}
