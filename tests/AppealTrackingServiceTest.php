<?php

namespace Tests;

use App\Services\AppealTrackingService;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests the public appeal-tracking rules: the verifier gate (anti-enumeration)
 * and the public-safe projection (response text hidden until responded/closed).
 */
class AppealTrackingServiceTest extends TestCase
{
    private function row(array $over = []): array
    {
        return array_merge([
            'appeal_number' => 'ОС-2026-0001',
            'subject'       => 'Тестовая тема',
            'category'      => 'question',
            'status'        => 'new',
            'created_at'    => '2026-07-18 09:00:00',
            'responded_at'  => null,
            'response_text' => null,
            'full_name'     => 'Иванов Иван Иванович',
            'email'         => 'ivan@example.com',
        ], $over);
    }

    public function testMissingRowNeverMatches(): void
    {
        $this->assertFalse(AppealTrackingService::verifierMatches(null, 'Иванов'));
    }

    public function testEmptyVerifierNeverMatches(): void
    {
        $this->assertFalse(AppealTrackingService::verifierMatches($this->row(), '   '));
    }

    public function testEmailMatchesCaseInsensitively(): void
    {
        $this->assertTrue(AppealTrackingService::verifierMatches($this->row(), 'IVAN@Example.com'));
    }

    public function testSurnameSubstringMatches(): void
    {
        $this->assertTrue(AppealTrackingService::verifierMatches($this->row(), 'иванов'));
    }

    public function testWrongVerifierDoesNotMatch(): void
    {
        $this->assertFalse(AppealTrackingService::verifierMatches($this->row(), 'Петров'));
        $this->assertFalse(AppealTrackingService::verifierMatches($this->row(), 'other@example.com'));
    }

    public function testEmailMatchIgnoredWhenAppellantLeftNoEmail(): void
    {
        // Submitted with phone only → empty email must not match an empty verifier-as-email.
        $row = $this->row(['email' => '']);
        $this->assertFalse(AppealTrackingService::verifierMatches($row, ''));
        $this->assertTrue(AppealTrackingService::verifierMatches($row, 'Иванов'));
    }

    public function testResponseTextHiddenWhileUnanswered(): void
    {
        foreach (['new', 'in_review'] as $status) {
            $view = AppealTrackingService::trackingView($this->row([
                'status' => $status,
                'responded_at' => '2026-07-19 10:00:00',
                'response_text' => 'секретный ответ',
            ]));
            $this->assertNull($view['response_text'], "$status must not leak response");
            $this->assertNull($view['responded_at']);
            $this->assertSame($status, $view['status']);
        }
    }

    public function testResponseTextExposedOnceResponded(): void
    {
        $view = AppealTrackingService::trackingView($this->row([
            'status' => 'responded',
            'responded_at' => '2026-07-19 10:00:00',
            'response_text' => 'Ваше обращение рассмотрено.',
        ]));
        $this->assertSame('Ваше обращение рассмотрено.', $view['response_text']);
        $this->assertSame('2026-07-19 10:00:00', $view['responded_at']);
        // No raw PII (full_name/email) leaks into the public view.
        $this->assertArrayNotHasKey('full_name', $view);
        $this->assertArrayNotHasKey('email', $view);
    }
}
