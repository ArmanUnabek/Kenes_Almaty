<?php

namespace Tests;

use App\Services\AuditSanitizer;
use PHPUnit\Framework\TestCase;

class AuditSanitizerTest extends TestCase
{
    public function testRedactsPasswordFields(): void
    {
        $input = [
            'username' => 'admin',
            'password' => 'secret123',
            'password_hash' => '$2y$10$hash',
            'password_confirm' => 'secret123',
            'token' => 'abc',
            'csrf_token' => 'xyz',
        ];

        $result = AuditSanitizer::sanitize($input);

        $this->assertSame('admin', $result['username']);
        $this->assertSame('[REDACTED]', $result['password']);
        $this->assertSame('[REDACTED]', $result['password_hash']);
        $this->assertSame('[REDACTED]', $result['password_confirm']);
        $this->assertSame('[REDACTED]', $result['token']);
        $this->assertSame('[REDACTED]', $result['csrf_token']);
    }

    public function testSanitizesScanPayloads(): void
    {
        $input = [
            'scans' => [
                [
                    'name' => 'scan.pdf',
                    'type' => 'application/pdf',
                    'data' => str_repeat('A', 120),
                ],
                [
                    'file_name' => 'photo.jpg',
                    'scan_type' => 'image/jpeg',
                    'file_size' => 4096,
                ],
            ],
        ];

        $result = AuditSanitizer::sanitize($input);

        $this->assertSame('scan.pdf', $result['scans'][0]['name']);
        $this->assertSame('application/pdf', $result['scans'][0]['type']);
        $this->assertSame(120, $result['scans'][0]['bytes']);
        $this->assertArrayNotHasKey('data', $result['scans'][0]);

        $this->assertSame('photo.jpg', $result['scans'][1]['name']);
        $this->assertSame('image/jpeg', $result['scans'][1]['type']);
        $this->assertSame(4096, $result['scans'][1]['bytes']);
    }

    public function testNullInputReturnsNull(): void
    {
        $this->assertNull(AuditSanitizer::sanitize(null));
    }
}
