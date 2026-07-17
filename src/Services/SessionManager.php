<?php

namespace App\Services;

use PDO;

class SessionManager
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function trackSession(int $userId, string $sessionId, string $ip, ?string $userAgent): void
    {
        $isRemembered = !empty($_SESSION['_remember']);
        // expires_at is computed by the DB itself (DATE_ADD(NOW(), ...) / NOW() + make_interval / datetime('now', ...)),
        // exactly like PdoSessionHandler::write(), instead of as a PHP-formatted wall-clock
        // string. Previously this method wrote a PHP date('Y-m-d H:i:s', ...) literal, which
        // depends on PHP's effective timezone, while validateSession()/read()/write() all
        // compare against the DB's own NOW(). If the DB server's session/global time_zone
        // doesn't agree with PHP's timezone, that literal can already look expired to the
        // DB the instant it's written — and it lives that way until this same request's
        // PdoSessionHandler::write() (fully DB-clock based) overwrites it at shutdown. Any
        // concurrent request landing in that gap sees validateSession() = false and (via
        // checkAuth()) gets session_destroy()'d, logging the user out right after login.
        // Doing the arithmetic in SQL removes the clock/timezone mismatch entirely.
        $lifetime = $isRemembered ? 86400 * 30 : (SESSION_IDLE_TIMEOUT_SECONDS ?? 7200);

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (stripos($driver, 'mysql') !== false) {
            $this->db->prepare(
                'INSERT INTO user_sessions (id, user_id, ip_address, user_agent, last_active, expires_at)
                 VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
                 ON DUPLICATE KEY UPDATE ip_address = VALUES(ip_address), user_agent = VALUES(user_agent), last_active = NOW(), expires_at = VALUES(expires_at)'
            )->execute([$sessionId, $userId, $ip, $userAgent, $lifetime]);
        } elseif (stripos($driver, 'pgsql') !== false) {
            $this->db->prepare(
                'INSERT INTO user_sessions (id, user_id, ip_address, user_agent, last_active, expires_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW() + make_interval(secs => ?))
                 ON CONFLICT (id) DO UPDATE SET ip_address = EXCLUDED.ip_address, user_agent = EXCLUDED.user_agent, last_active = NOW(), expires_at = EXCLUDED.expires_at'
            )->execute([$sessionId, $userId, $ip, $userAgent, $lifetime]);
        } else {
            // Не INSERT OR REPLACE: REPLACE удалил бы строку и обнулил колонку data
            // (в этой же таблице PdoSessionHandler хранит данные PHP-сессии).
            $this->db->prepare(
                "INSERT INTO user_sessions (id, user_id, ip_address, user_agent, last_active, expires_at)
                 VALUES (?, ?, ?, ?, datetime('now'), datetime('now', '+{$lifetime} seconds'))
                 ON CONFLICT(id) DO UPDATE SET user_id = excluded.user_id, ip_address = excluded.ip_address,
                     user_agent = excluded.user_agent, last_active = excluded.last_active, expires_at = excluded.expires_at"
            )->execute([$sessionId, $userId, $ip, $userAgent]);
        }
    }

    public function getActiveSessions(int $userId): array
    {
        $this->cleanExpired();
        $stmt = $this->db->prepare(
            'SELECT id, user_id, ip_address, user_agent, last_active, expires_at, created_at
             FROM user_sessions WHERE user_id = ? ORDER BY last_active DESC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        $currentSid = session_id();
        foreach ($rows as &$row) {
            $row['is_current'] = ($row['id'] === $currentSid);
        }
        return $rows;
    }

    public function terminateSession(string $sessionId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM user_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        return $stmt->rowCount() > 0;
    }

    public function terminateAllSessions(int $userId, ?string $exceptSessionId = null): int
    {
        if ($exceptSessionId !== null) {
            $stmt = $this->db->prepare('DELETE FROM user_sessions WHERE user_id = ? AND id != ?');
            $stmt->execute([$userId, $exceptSessionId]);
        } else {
            $stmt = $this->db->prepare('DELETE FROM user_sessions WHERE user_id = ?');
            $stmt->execute([$userId]);
        }
        return (int) $stmt->rowCount();
    }

    public function updateLastActive(string $sessionId): void
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (stripos($driver, 'mysql') !== false || stripos($driver, 'pgsql') !== false) {
            $this->db->prepare('UPDATE user_sessions SET last_active = NOW() WHERE id = ?')
                ->execute([$sessionId]);
        } else {
            $this->db->prepare("UPDATE user_sessions SET last_active = datetime('now') WHERE id = ?")
                ->execute([$sessionId]);
        }
    }

    /**
     * Validate that a session is still active (not terminated, not expired).
     * Returns true if valid, false otherwise.
     */
    public function validateSession(string $sessionId): bool
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowExpr = (stripos($driver, 'mysql') !== false || stripos($driver, 'pgsql') !== false)
            ? 'NOW()' : "datetime('now')";

        $stmt = $this->db->prepare(
            "SELECT id, ip_address, user_agent FROM user_sessions WHERE id = ? AND expires_at > {$nowExpr}"
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if ($row['ip_address'] !== $ip) {
            $this->logAnomaly($sessionId, 'ip_changed', $row['ip_address'], $ip);
        }

        if ($row['user_agent'] !== null && $row['user_agent'] !== $ua) {
            $this->logAnomaly($sessionId, 'user_agent_changed', $row['user_agent'], $ua);
        }

        return true;
    }

    private function logAnomaly(string $sessionId, string $type, string $expected, string $actual): void
    {
        try {
            $stmt = $this->db->prepare('SELECT user_id FROM user_sessions WHERE id = ?');
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch();
            $userId = $row ? (int) $row['user_id'] : 0;

            SecurityAuditService::log($this->db, 'SESSION_ANOMALY', 'user_sessions', $userId, [
                'session_id' => $sessionId,
                'anomaly_type' => $type,
                'expected' => $expected,
                'actual' => $actual,
            ], $userId);
        } catch (\Throwable $e) {
            error_log('SessionManager::logAnomaly failed: ' . $e->getMessage());
        }
    }

    private function cleanExpired(): void
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowExpr = (stripos($driver, 'mysql') !== false || stripos($driver, 'pgsql') !== false)
            ? 'NOW()' : "datetime('now')";
        try {
            $this->db->exec("DELETE FROM user_sessions WHERE expires_at < {$nowExpr}");
        } catch (\Throwable $e) {
            // Non-critical
        }
    }
}
