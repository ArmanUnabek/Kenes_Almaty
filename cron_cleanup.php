<?php
/**
 * Maintenance cron: clean up audit logs, expired tokens, old rate-limit files,
 * and stale email queue entries.
 *
 * Run daily:
 *   GET /cron_cleanup.php?token=<CRON_TOKEN>
 * or from CLI:
 *   php cron_cleanup.php
 *
 * Env variables:
 *   AUDIT_LOG_RETENTION_DAYS  - days to keep audit_logs (default 365)
 *   EMAIL_QUEUE_RETENTION_DAYS - days to keep sent/failed emails (default 90)
 */

require_once __DIR__ . '/config.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $expectedToken = envValue('CRON_TOKEN');
    $providedToken = $_GET['token'] ?? '';
    if (!is_string($expectedToken) || $expectedToken === ''
        || !is_string($providedToken)
        || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещён'], JSON_ENCODE_FLAGS);
        exit;
    }
}

$db      = getDBConnection();
$result  = [];
$errors  = [];

// ─── 1. Audit logs ────────────────────────────────────────────────────────────
$retentionDays = max(30, (int)(envValue('AUDIT_LOG_RETENTION_DAYS') ?? 365));
try {
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
    $stmt   = $db->prepare('DELETE FROM audit_logs WHERE created_at < ?');
    $stmt->execute([$cutoff]);
    $result['audit_logs_deleted'] = $stmt->rowCount();
} catch (\Throwable $e) {
    $errors[] = 'audit_logs: ' . $e->getMessage();
    error_log('cron_cleanup audit_logs: ' . $e->getMessage());
}

// ─── 2. Activity logs (keep 180 days by default) ──────────────────────────────
$activityRetention = max(30, (int)(envValue('ACTIVITY_LOG_RETENTION_DAYS') ?? 180));
try {
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$activityRetention} days"));
    $stmt   = $db->prepare('DELETE FROM activity_logs WHERE created_at < ?');
    $stmt->execute([$cutoff]);
    $result['activity_logs_deleted'] = $stmt->rowCount();
} catch (\Throwable $e) {
    $errors[] = 'activity_logs: ' . $e->getMessage();
    error_log('cron_cleanup activity_logs: ' . $e->getMessage());
}

// ─── 3. Telegram expired tokens & link codes ──────────────────────────────────
try {
    $stmt = $db->prepare('DELETE FROM telegram_login_tokens WHERE expires_at < NOW()');
    $stmt->execute();
    $result['telegram_login_tokens_deleted'] = $stmt->rowCount();
} catch (\Throwable $e) {
    $errors[] = 'telegram_login_tokens: ' . $e->getMessage();
}

try {
    $stmt = $db->prepare('DELETE FROM telegram_link_codes WHERE expires_at < NOW()');
    $stmt->execute();
    $result['telegram_link_codes_deleted'] = $stmt->rowCount();
} catch (\Throwable $e) {
    $errors[] = 'telegram_link_codes: ' . $e->getMessage();
}

// ─── 4. Password reset tokens ─────────────────────────────────────────────────
try {
    $stmt = $db->prepare('DELETE FROM password_reset_tokens WHERE expires_at < NOW() OR used_at IS NOT NULL');
    $stmt->execute();
    $result['password_reset_tokens_deleted'] = $stmt->rowCount();
} catch (\Throwable $e) {
    $errors[] = 'password_reset_tokens: ' . $e->getMessage();
}

// ─── 5. Email queue (keep sent/failed for N days) ─────────────────────────────
$emailRetention = max(7, (int)(envValue('EMAIL_QUEUE_RETENTION_DAYS') ?? 90));
try {
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$emailRetention} days"));
    $stmt   = $db->prepare("DELETE FROM email_queue WHERE status IN ('sent','failed') AND created_at < ?");
    $stmt->execute([$cutoff]);
    $result['email_queue_deleted'] = $stmt->rowCount();
} catch (\Throwable $e) {
    $errors[] = 'email_queue: ' . $e->getMessage();
}

// ─── 6. Rate-limit cache files (older than 24 hours) ─────────────────────────
try {
    $rateLimitDir = __DIR__ . '/.rate_limit';
    $cleaned = 0;
    if (is_dir($rateLimitDir)) {
        foreach (glob($rateLimitDir . '/*.json') as $file) {
            if (filemtime($file) < time() - 86400) {
                unlink($file);
                $cleaned++;
            }
        }
    }
    $result['rate_limit_files_deleted'] = $cleaned;
} catch (\Throwable $e) {
    $errors[] = 'rate_limit_files: ' . $e->getMessage();
}

$result['errors'] = $errors;
$result['ran_at']  = date('Y-m-d H:i:s');

if ($isCli) {
    foreach ($result as $key => $value) {
        if ($key === 'errors') {
            continue;
        }
        echo "[OK] {$key}: {$value}" . PHP_EOL;
    }
    if (!empty($errors)) {
        foreach ($errors as $err) {
            fwrite(STDERR, '[ERROR] ' . $err . PHP_EOL);
        }
        exit(1);
    }
} else {
    http_response_code(empty($errors) ? 200 : 207);
    echo json_encode($result, JSON_ENCODE_FLAGS);
}
