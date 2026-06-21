<?php
namespace App\Services;

/**
 * RFC 6238 TOTP — чистый PHP, без внешних зависимостей.
 * 30-секундный интервал, HMAC-SHA1, 6 цифр.
 */
class TotpService
{
    private const DIGITS   = 6;
    private const STEP     = 30;
    private const DRIFT    = 1; // допуск ±1 шаг (±30 сек)

    /**
     * Генерирует случайный Base32 секрет (20 байт = 160 бит).
     */
    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        return self::base32Encode($bytes);
    }

    /**
     * Возвращает текущий OTP для секрета.
     */
    public static function getCode(string $secret): string
    {
        $counter = (int)(time() / self::STEP);
        return self::hotp($secret, $counter);
    }

    /**
     * Проверяет введённый код с допуском ±DRIFT шагов.
     * При совпадении в $matchedCounter возвращается номер интервала (для защиты от повтора).
     */
    public static function verify(string $secret, string $code, ?int &$matchedCounter = null): bool
    {
        $matchedCounter = null;
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return false;
        // Reject empty or corrupt secrets before any HMAC is computed
        if (self::base32Decode($secret) === '') return false;
        $counter = (int)(time() / self::STEP);
        // Check current window first (most common case), then ±DRIFT
        foreach ([0, -1, 1] as $i) {
            if (abs($i) <= self::DRIFT && hash_equals(self::hotp($secret, $counter + $i), $code)) {
                $matchedCounter = $counter + $i;
                return true;
            }
        }
        return false;
    }

    /**
     * Генерирует URI для QR-кода (otpauth://totp/...).
     */
    public static function getUri(string $secret, string $account, string $issuer = 'Журнал ОС'): string
    {
        return sprintf(
            'otpauth://totp/%s%%3A%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($account),
            rawurlencode($secret),
            rawurlencode($issuer)
        );
    }

    /**
     * URL внешнего сервиса для QR-кода (не отправляет секрет на сервер — только данные URI).
     * Google Image Charts (chart.googleapis.com) отключён Google, поэтому используем
     * api.qrserver.com (goqr.me), отдающий PNG напрямую — подходит для <img src>.
     */
    public static function getQrUrl(string $uri): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($uri);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private static function hotp(string $secret, int $counter): string
    {
        $key  = self::base32Decode($secret);
        $msg  = pack('J', $counter);  // RFC 4226: 8-byte unsigned big-endian counter
        $hash = hash_hmac('sha1', $msg, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
            ((ord($hash[$offset + 3]) & 0xFF))
        ) % (10 ** self::DIGITS);
        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $v = 0;
        $vBits = 0;
        foreach (str_split($input) as $char) {
            $v = ($v << 8) | ord($char);
            $vBits += 8;
            while ($vBits >= 5) {
                $vBits -= 5;
                $output .= $alphabet[($v >> $vBits) & 31];
            }
        }
        if ($vBits > 0) {
            $output .= $alphabet[($v << (5 - $vBits)) & 31];
        }
        return $output;
    }

    private static function base32Decode(string $input): string
    {
        $input = strtoupper(str_replace(' ', '', $input));
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $v = 0;
        $vBits = 0;
        for ($i = 0; $i < strlen($input); $i++) {
            $pos = strpos($alphabet, $input[$i]);
            if ($pos === false) continue;
            $v = ($v << 5) | $pos;
            $vBits += 5;
            if ($vBits >= 8) {
                $vBits -= 8;
                $output .= chr(($v >> $vBits) & 0xFF);
            }
        }
        return $output;
    }
}
