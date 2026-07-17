<?php

namespace App\Services;

use PDO;
use SessionHandlerInterface;

class PdoSessionHandler implements SessionHandlerInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowExpr = (stripos($driver, 'mysql') !== false || stripos($driver, 'pgsql') !== false)
            ? 'NOW()' : "datetime('now')";

        $stmt = $this->db->prepare(
            "SELECT data FROM user_sessions WHERE id = ? AND expires_at > {$nowExpr}"
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();
        return $row ? (string) $row['data'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isRemembered = !empty($_SESSION['_remember']);
        $lifetime = $isRemembered ? 86400 * 30 : (SESSION_IDLE_TIMEOUT_SECONDS ?? 7200);
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (stripos($driver, 'mysql') !== false) {
            $stmt = $this->db->prepare(
                'INSERT INTO user_sessions (id, user_id, data, ip_address, user_agent, expires_at, last_active)
                 VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
                 ON DUPLICATE KEY UPDATE data = VALUES(data), ip_address = VALUES(ip_address),
                     user_agent = VALUES(user_agent), expires_at = VALUES(expires_at), last_active = NOW()'
            );
            $stmt->execute([$id, $userId, $data, $ip, $ua, $lifetime]);
        } elseif (stripos($driver, 'pgsql') !== false) {
            $stmt = $this->db->prepare(
                'INSERT INTO user_sessions (id, user_id, data, ip_address, user_agent, expires_at, last_active)
                 VALUES (?, ?, ?, ?, ?, NOW() + make_interval(secs => ?), NOW())
                 ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, ip_address = EXCLUDED.ip_address,
                     user_agent = EXCLUDED.user_agent, expires_at = EXCLUDED.expires_at, last_active = NOW()'
            );
            $stmt->execute([$id, $userId, $data, $ip, $ua, $lifetime]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO user_sessions (id, user_id, data, ip_address, user_agent, expires_at, last_active)
                 VALUES (?, ?, ?, ?, ?, datetime('now', '+{$lifetime} seconds'), datetime('now'))
                 ON CONFLICT(id) DO UPDATE SET user_id = excluded.user_id, data = excluded.data,
                     ip_address = excluded.ip_address, user_agent = excluded.user_agent,
                     expires_at = excluded.expires_at, last_active = excluded.last_active"
            );
            $stmt->execute([$id, $userId, $data, $ip, $ua]);
        }

        return true;
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM user_sessions WHERE id = ?');
        $stmt->execute([$id]);
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        return true;
    }

    public function gc(int $max_lifetime): int
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowExpr = (stripos($driver, 'mysql') !== false || stripos($driver, 'pgsql') !== false)
            ? 'NOW()' : "datetime('now')";

        $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE expires_at < {$nowExpr}");
        $stmt->execute();

        return (int) $stmt->rowCount();
    }
}
