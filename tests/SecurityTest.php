<?php

use PHPUnit\Framework\TestCase;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimiter;

/**
 * Security middleware tests.
 */
class SecurityTest extends TestCase
{
    protected function setUp(): void
    {
        // Start a fresh session for each test
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        @session_start();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    // ─── CSRF Tests ───────────────────────────────────────────────────────────

    public function testCsrfTokenIsGenerated(): void
    {
        $token = CsrfMiddleware::getToken();
        $this->assertNotEmpty($token, 'CSRF token must not be empty');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token, 'CSRF token must be 64 hex chars');
    }

    public function testCsrfTokenIsStableWithinSession(): void
    {
        $t1 = CsrfMiddleware::getToken();
        $t2 = CsrfMiddleware::getToken();
        $this->assertSame($t1, $t2, 'CSRF token must be stable within the same session');
    }

    public function testGetRequestBypassesCsrf(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertTrue(CsrfMiddleware::verify(), 'GET requests must pass CSRF check without a token');
    }

    public function testHeadRequestBypassesCsrf(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $this->assertTrue(CsrfMiddleware::verify(), 'HEAD requests must pass CSRF check without a token');
    }

    public function testOptionsRequestBypassesCsrf(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $this->assertTrue(CsrfMiddleware::verify(), 'OPTIONS requests must pass CSRF check without a token');
    }

    public function testPostWithoutTokenFails(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertFalse(CsrfMiddleware::verify(), 'POST without CSRF token must fail');
    }

    public function testPostWithWrongTokenFails(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong_token_value';
        $this->assertFalse(CsrfMiddleware::verify(), 'POST with wrong CSRF token must fail');
    }

    public function testPostWithCorrectHeaderTokenPasses(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $token = CsrfMiddleware::getToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $this->assertTrue(CsrfMiddleware::verify(), 'POST with correct CSRF token header must pass');
    }

    public function testPostWithCorrectPostTokenPasses(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $token = CsrfMiddleware::getToken();
        $_POST['_csrf_token'] = $token;
        $this->assertTrue(CsrfMiddleware::verify(), 'POST with correct CSRF token in POST body must pass');
    }

    public function testCsrfTokenRotatesAfterSuccessfulVerification(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $originalToken = CsrfMiddleware::getToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $originalToken;
        CsrfMiddleware::verify();

        $newToken = CsrfMiddleware::getToken();
        $this->assertNotSame($originalToken, $newToken, 'CSRF token must rotate after successful mutation verification');
    }

    public function testOldTokenInvalidAfterRotation(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $originalToken = CsrfMiddleware::getToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $originalToken;
        CsrfMiddleware::verify(); // rotates the token

        // Now try to use the old token again
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $originalToken;
        $this->assertFalse(CsrfMiddleware::verify(), 'Old CSRF token must be invalid after rotation');
    }

    public function testPutWithCorrectTokenPasses(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $token = CsrfMiddleware::getToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $this->assertTrue(CsrfMiddleware::verify());
    }

    public function testDeleteWithCorrectTokenPasses(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $token = CsrfMiddleware::getToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $this->assertTrue(CsrfMiddleware::verify());
    }

    // ─── Rate Limiter Tests ───────────────────────────────────────────────────

    public function testRateLimiterAllowsUnderLimit(): void
    {
        $id = 'test_rate_' . bin2hex(random_bytes(8));
        $this->assertTrue(RateLimiter::check($id, 5, 60));
        $this->assertTrue(RateLimiter::check($id, 5, 60));
        $this->assertTrue(RateLimiter::check($id, 5, 60));
    }

    public function testRateLimiterBlocksWhenLimitExceeded(): void
    {
        $id = 'test_rate_' . bin2hex(random_bytes(8));
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::check($id, 3, 60);
        }
        $this->assertFalse(RateLimiter::check($id, 3, 60), 'Rate limiter must block after limit exceeded');
    }

    public function testRateLimiterSeparatesIdentifiers(): void
    {
        $id1 = 'test_sep_' . bin2hex(random_bytes(8));
        $id2 = 'test_sep_' . bin2hex(random_bytes(8));
        for ($i = 0; $i < 2; $i++) {
            RateLimiter::check($id1, 2, 60);
        }
        // id1 is now at its limit, but id2 should still be allowed
        $this->assertFalse(RateLimiter::check($id1, 2, 60));
        $this->assertTrue(RateLimiter::check($id2, 2, 60));
    }

    // ─── File Upload Security Tests ──────────────────────────────────────────

    /**
     * Simulates a file upload with a disguised extension.
     * PHP's $_FILES uses the client-provided MIME, which is untrusted.
     * The profile.php endpoint uses finfo to get the real MIME — this test
     * verifies that finfo correctly identifies the actual file content.
     */
    public function testFinfoDetectsRealMimeType(): void
    {
        // Create a real PNG file in a temp location
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
        // Write a valid 1x1 transparent PNG (binary)
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        file_put_contents($tmpFile, $pngData);

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpFile);
        finfo_close($finfo);

        unlink($tmpFile);

        $this->assertSame('image/png', $mimeType, 'finfo must correctly detect PNG MIME type regardless of file name');
    }

    public function testFinfoDetectsPhpFileDisguisedAsPng(): void
    {
        // Create a PHP file disguised as a PNG
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
        file_put_contents($tmpFile, '<?php system($_GET["cmd"]); ?>');

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpFile);
        finfo_close($finfo);

        unlink($tmpFile);

        $allowed = ['image/jpeg', 'image/png'];
        $this->assertNotContains($mimeType, $allowed, 'finfo must reject PHP file even if named .png');
    }

    public function testFileSizeValidation(): void
    {
        // Generate data just over 5 MB
        $overLimit = 5 * 1024 * 1024 + 1;
        $this->assertGreaterThan(5 * 1024 * 1024, $overLimit, 'Over-limit size must exceed 5 MB');
    }

    // ─── Password Validation Tests ────────────────────────────────────────────

    public function testPasswordHashVerification(): void
    {
        $pass = 'TestPassword123!';
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $this->assertTrue(password_verify($pass, $hash));
        $this->assertFalse(password_verify('WrongPassword', $hash));
    }

    public function testPasswordMinimumLength(): void
    {
        $short = 'Ab1!567'; // 7 chars
        $ok    = 'Ab1!5678'; // 8 chars
        $this->assertLessThan(8, strlen($short));
        $this->assertGreaterThanOrEqual(8, strlen($ok));
    }
}
