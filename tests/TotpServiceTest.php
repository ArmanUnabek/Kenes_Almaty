<?php

namespace Tests;

use App\Services\TotpService;
use PHPUnit\Framework\TestCase;

class TotpServiceTest extends TestCase
{
    public function testVerifyAcceptsCurrentCodeAndReportsCounter(): void
    {
        $secret = TotpService::generateSecret();
        $code = TotpService::getCode($secret);

        $matched = null;
        $this->assertTrue(TotpService::verify($secret, $code, $matched));
        $this->assertIsInt($matched);
    }

    public function testVerifyRejectsWrongCodeAndLeavesCounterNull(): void
    {
        $secret = TotpService::generateSecret();
        $matched = 123;
        $this->assertFalse(TotpService::verify($secret, '000000', $matched));
        $this->assertNull($matched);
    }

    public function testVerifyRejectsMalformedInput(): void
    {
        $secret = TotpService::generateSecret();
        $this->assertFalse(TotpService::verify($secret, 'abcdef'));
        $this->assertFalse(TotpService::verify($secret, '12345'));
        $this->assertFalse(TotpService::verify('', '123456'));
    }

    public function testBackupCodesAreUniqueAndMatchable(): void
    {
        $codes = TotpService::generateBackupCodes(10);
        $this->assertCount(10, $codes);
        $this->assertSame($codes, array_values(array_unique($codes)));

        $hashes = TotpService::hashBackupCodes($codes);
        // Matching is case-insensitive and ignores the dash separator.
        $variant = strtolower(str_replace('-', '', $codes[3]));
        $this->assertSame(3, TotpService::matchBackupCode($variant, $hashes));
    }

    public function testBackupCodeMismatchReturnsMinusOne(): void
    {
        $hashes = TotpService::hashBackupCodes(TotpService::generateBackupCodes(3));
        $this->assertSame(-1, TotpService::matchBackupCode('ZZZZZ-ZZZZZ', $hashes));
        $this->assertSame(-1, TotpService::matchBackupCode('', $hashes));
    }

    public function testConsumedBackupCodeCannotBeReused(): void
    {
        $codes = TotpService::generateBackupCodes(5);
        $hashes = TotpService::hashBackupCodes($codes);

        $idx = TotpService::matchBackupCode($codes[2], $hashes);
        $this->assertSame(2, $idx);

        // Simulate consumption (as auth.php does) and confirm reuse fails.
        unset($hashes[$idx]);
        $remaining = array_values($hashes);
        $this->assertSame(-1, TotpService::matchBackupCode($codes[2], $remaining));
        $this->assertCount(4, $remaining);
    }

    public function testQrUrlIsGeneratedLocallyWithoutLeakingSecret(): void
    {
        // The QR is rendered server-side (chillerlan/php-qrcode) and returned as an
        // inline data: URI, so the otpauth secret never leaves the server. It must
        // not be an external image service, and the raw secret must not appear in
        // the URL (it is encoded inside the rendered image, not the URI string).
        $uri = 'otpauth://totp/Test%3Auser?secret=ABCSECRET123';
        $url = TotpService::getQrUrl($uri);

        $this->assertStringStartsWith('data:image/', $url);
        $this->assertStringContainsString(';base64,', $url);
        $this->assertStringNotContainsString('api.qrserver.com', $url);
        $this->assertStringNotContainsString('chart.googleapis.com', $url);
        $this->assertStringNotContainsString('ABCSECRET123', $url);
    }
}
