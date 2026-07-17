<?php

namespace App\Services;

/**
 * Web Push notifications via VAPID (RFC 8292) with aes128gcm payload encryption (RFC 8291).
 * No external library required — uses PHP's built-in OpenSSL extension.
 * Requires PHP 8.0+ (openssl_pkey_derive) and openssl extension.
 */
class PushNotificationService
{
    public static function isConfigured(): bool
    {
        $enabled = filter_var(envValue('PUSH_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
        return $enabled
            && !empty(envValue('VAPID_PUBLIC_KEY'))
            && !empty(envValue('VAPID_PRIVATE_KEY'));
    }

    /**
     * Send a push notification to all subscriptions of a user.
     * Returns the number of successful deliveries.
     */
    public static function sendToUser(\PDO $db, int $userId, string $title, string $body, string $url = ''): int
    {
        if (!self::isConfigured()) {
            return 0;
        }

        $stmt = $db->prepare(
            "SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $subscriptions = $stmt->fetchAll();

        $sent   = 0;
        $stmtDel = null;

        foreach ($subscriptions as $sub) {
            $result = self::send([
                'endpoint' => $sub['endpoint'],
                'p256dh'   => $sub['p256dh'],
                'auth'     => $sub['auth'],
            ], $title, $body, $url);

            if ($result === 'expired') {
                // Subscription is no longer valid — clean it up
                if ($stmtDel === null) {
                    $stmtDel = $db->prepare("DELETE FROM push_subscriptions WHERE id = ?");
                }
                $stmtDel->execute([(int)$sub['id']]);
            } elseif ($result === 'ok') {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Send a push notification to a single subscription.
     * Returns 'ok', 'expired' (410/404 → subscription should be deleted), or 'error'.
     */
    public static function send(array $subscription, string $title, string $body, string $url = ''): string
    {
        if (!self::isConfigured()) {
            return 'error';
        }

        $vapidPublicKeyB64  = envValue('VAPID_PUBLIC_KEY') ?? '';
        $vapidPrivateKeyB64 = envValue('VAPID_PRIVATE_KEY') ?? '';
        $subject = envValue('VAPID_SUBJECT', 'mailto:admin@example.com') ?? 'mailto:admin@example.com';

        $endpoint = $subscription['endpoint'];
        $parsed   = parse_url($endpoint);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            error_log('PushNotificationService: invalid endpoint: ' . $endpoint);
            return 'error';
        }
        $audience = $parsed['scheme'] . '://' . $parsed['host'];

        // Load VAPID private key from base64url-encoded raw EC scalar
        $privKey = self::importVapidPrivateKey($vapidPrivateKeyB64);
        if ($privKey === null) {
            error_log('PushNotificationService: failed to load VAPID private key');
            return 'error';
        }

        $jwt = self::createVapidJwt($audience, $subject, $privKey);

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => $url,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $hasEncryption = !empty($subscription['p256dh']) && !empty($subscription['auth']);
        $postData      = '';
        $extraHeaders  = [];

        if ($hasEncryption && $payload !== false) {
            $encrypted = self::encryptPayload($payload, $subscription);
            if ($encrypted !== null) {
                $postData      = $encrypted;
                $extraHeaders  = [
                    "Content-Type: application/octet-stream",
                    "Content-Encoding: aes128gcm",
                    "Content-Length: " . strlen($encrypted),
                ];
            } else {
                $extraHeaders = ["Content-Length: 0"];
            }
        } else {
            $extraHeaders = ["Content-Length: 0"];
        }

        $headers = array_merge([
            "TTL: 86400",
            "Authorization: vapid t={$jwt},k={$vapidPublicKeyB64}",
        ], $extraHeaders);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $postData,
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        @file_get_contents($endpoint, false, $context);

        $httpCode = 0;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) {
                    $httpCode = (int)$m[1];
                }
            }
        }

        if ($httpCode === 410 || $httpCode === 404) {
            return 'expired';
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return 'ok';
        }
        error_log("PushNotificationService: push returned HTTP {$httpCode} for endpoint " . substr($endpoint, 0, 80));
        return 'error';
    }

    // -------------------------------------------------------------------------
    // VAPID JWT
    // -------------------------------------------------------------------------

    private static function importVapidPrivateKey(string $base64UrlKey): ?\OpenSSLAsymmetricKey
    {
        try {
            $raw = self::base64urlDecode($base64UrlKey);
            if (strlen($raw) !== 32) {
                error_log('PushNotificationService: VAPID private key must be 32 bytes, got ' . strlen($raw));
                return null;
            }

            // Wrap raw 32-byte EC scalar into PKCS8 DER for prime256v1
            $pkcs8Prefix = "\x30\x41\x02\x01\x00\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
                         . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x04\x27\x30\x25\x02\x01"
                         . "\x01\x04\x20";
            $der = $pkcs8Prefix . $raw;
            $pem = "-----BEGIN PRIVATE KEY-----\n"
                 . chunk_split(base64_encode($der), 64, "\n")
                 . "-----END PRIVATE KEY-----\n";

            $key = openssl_pkey_get_private($pem);
            return ($key !== false) ? $key : null;
        } catch (\Throwable $e) {
            error_log('PushNotificationService::importVapidPrivateKey: ' . $e->getMessage());
            return null;
        }
    }

    private static function createVapidJwt(string $audience, string $subject, \OpenSSLAsymmetricKey $privKey): string
    {
        $header  = self::base64urlEncode((string)json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::base64urlEncode((string)json_encode([
            'aud' => $audience,
            'exp' => time() + 43200,
            'sub' => $subject,
        ]));

        $signingInput = $header . '.' . $payload;
        openssl_sign($signingInput, $derSig, $privKey, OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . self::base64urlEncode(self::derToCompact($derSig));
    }

    private static function derToCompact(string $der): string
    {
        // SEQUENCE { INTEGER r, INTEGER s } — skip outer SEQUENCE tag + length
        $offset = 2;
        // r
        $offset++; // skip INTEGER tag 0x02
        $rLen = ord($der[$offset++]);
        $r    = substr($der, $offset, $rLen);
        $offset += $rLen;
        // s
        $offset++; // skip INTEGER tag 0x02
        $sLen = ord($der[$offset++]);
        $s    = substr($der, $offset, $sLen);

        // Normalize to exactly 32 bytes each
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    // -------------------------------------------------------------------------
    // aes128gcm payload encryption (RFC 8291)
    // -------------------------------------------------------------------------

    /**
     * Returns encrypted binary string or null on failure.
     */
    private static function encryptPayload(string $plaintext, array $subscription): ?string
    {
        if (!function_exists('openssl_pkey_derive')) {
            // openssl_pkey_derive requires PHP 8.0+
            return null;
        }

        try {
            $uaPublicKeyRaw = self::base64urlDecode($subscription['p256dh']); // 65 bytes
            $authSecret     = self::base64urlDecode($subscription['auth']);   // 16 bytes

            if (strlen($uaPublicKeyRaw) !== 65 || strlen($authSecret) !== 16) {
                return null;
            }

            // Generate ephemeral server EC key pair (P-256)
            $serverKey = openssl_pkey_new([
                'curve_name'       => 'prime256v1',
                'private_key_type' => OPENSSL_KEYTYPE_EC,
            ]);
            if ($serverKey === false) {
                return null;
            }

            $details = openssl_pkey_get_details($serverKey);
            if ($details === false || empty($details['ec'])) {
                return null;
            }

            // Server public key in uncompressed form (04 || x || y)
            $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
            $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
            $serverPublicRaw = "\x04" . $x . $y;

            // Import UA public key
            $uaKey = self::importP256PublicKey($uaPublicKeyRaw);
            if ($uaKey === false) {
                return null;
            }

            // ECDH shared secret
            $sharedSecret = openssl_pkey_derive($uaKey, $serverKey);
            if ($sharedSecret === false || strlen($sharedSecret) === 0) {
                return null;
            }

            // RFC 8291 § 3.3 key derivation
            // PRK_key = HKDF(salt=authSecret, IKM=sharedSecret, info="WebPush: info\x00" || ua_public || as_public, 32)
            $keyInfo = "WebPush: info\x00" . $uaPublicKeyRaw . $serverPublicRaw;
            $prkKey  = self::hkdfSha256($authSecret, $sharedSecret, $keyInfo, 32);

            $salt = random_bytes(16);

            // Content encryption key (16 bytes) and nonce (12 bytes)
            $cek   = self::hkdfSha256($salt, $prkKey, "Content-Encoding: aes128gcm\x00\x01", 16);
            $nonce = self::hkdfSha256($salt, $prkKey, "Content-Encoding: nonce\x00\x01", 12);

            // Pad plaintext: append 0x02 (last record delimiter)
            $paddedPlaintext = $plaintext . "\x02";

            // AES-128-GCM
            $tag        = '';
            $ciphertext = openssl_encrypt($paddedPlaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
            if ($ciphertext === false) {
                return null;
            }

            // Build aes128gcm record (RFC 8291 § 2.1):
            // salt(16) + rs(4 big-endian) + idlen(1) + server_public(65) + ciphertext + tag(16)
            $rs     = pack('N', 4096); // record size
            $idlen  = chr(65);         // server public key length

            return $salt . $rs . $idlen . $serverPublicRaw . $ciphertext . $tag;
        } catch (\Throwable $e) {
            error_log('PushNotificationService::encryptPayload: ' . $e->getMessage());
            return null;
        }
    }

    private static function importP256PublicKey(string $rawBytes): \OpenSSLAsymmetricKey|false
    {
        // SubjectPublicKeyInfo DER for P-256 uncompressed key (65 bytes)
        // Prefix: SEQUENCE { SEQUENCE { OID ecPublicKey, OID prime256v1 }, BIT STRING 0x00 + key }
        $derPrefix = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
                   . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";
        $der = $derPrefix . $rawBytes;
        $pem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem);
    }

    private static function hkdfSha256(string $salt, string $ikm, string $info, int $length): string
    {
        $prk    = hash_hmac('sha256', $ikm, $salt, true);
        $output = '';
        $t      = '';
        $i      = 0;
        while (strlen($output) < $length) {
            $i++;
            $t       = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
            $output .= $t;
        }
        return substr($output, 0, $length);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string
    {
        $pad  = (4 - strlen($data) % 4) % 4;
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $pad));
    }
}
