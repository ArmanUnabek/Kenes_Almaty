<?php

namespace App;

use App\Middleware\CsrfMiddleware;

abstract class ApiController
{
    protected \PDO $db;
    protected array $JSON_FLAGS = [\JSON_UNESCAPED_UNICODE, \JSON_UNESCAPED_SLASHES];
    protected ?array $currentUser = null;

    public function __construct()
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->db = getDBConnection();
        CsrfMiddleware::init();
        
        if (session_status() === \PHP_SESSION_NONE) {
            session_start();
        }
        
        $this->currentUser = getCurrentUser();
    }

    protected function json($data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data, ...$this->JSON_FLAGS);
        exit;
    }

    protected function error(string $message, int $code = 400): void
    {
        $this->json(['error' => $message], $code);
    }

    protected function success($data = null, string $message = 'Success', int $code = 200): void
    {
        $response = ['success' => true];
        if ($message) {
            $response['message'] = $message;
        }
        if ($data !== null) {
            $response['data'] = $data;
        }
        $this->json($response, $code);
    }

    protected function paginated(array $items, int $total, int $page, int $limit): void
    {
        $this->json([
            'items' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int)ceil($total / max(1, $limit))
            ]
        ]);
    }

    protected function requireAuth(): void
    {
        if (!$this->currentUser) {
            $this->error('Требуется авторизация', 401);
        }
    }

    protected function requireRole(array $roles): void
    {
        $this->requireAuth();
        $userRole = normalizeRole($this->currentUser['role'] ?? 'viewer');
        if (!in_array($userRole, $roles, true)) {
            $this->error('Недостаточно прав', 403);
        }
    }

    protected function requireWriteAccess(): void
    {
        $this->requireRole(['admin', 'moderator']);
    }

    protected function requireDeleteAccess(): void
    {
        $this->requireRole(['admin']);
    }

    protected function requireCsrf(): void
    {
        CsrfMiddleware::requireVerification();
    }

    protected function getJsonInput(): ?array
    {
        $input = file_get_contents('php://input');
        return json_decode($input, true);
    }

    protected function getQueryParam(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    protected function getPostParam(string $key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }

    protected function getCurrentRegionId(): ?int
    {
        if (!$this->currentUser) {
            return null;
        }
        $regionId = $this->currentUser['region_id'] ?? null;
        return $regionId ? (int)$regionId : null;
    }

    protected function canAccessRegion(int $regionId): bool
    {
        if (!$this->currentUser) {
            return false;
        }
        $userRole = normalizeRole($this->currentUser['role'] ?? 'viewer');
        if ($userRole === 'admin') {
            return true;
        }
        return (int)$regionId === (int)($this->currentUser['region_id'] ?? 0);
    }

    protected function logAction(string $table, int $entityId, string $action, ?array $oldData = null, ?array $newData = null): void
    {
        try {
            AuditLogger::log(
                $this->db,
                $table,
                $entityId,
                $action,
                $oldData,
                $newData,
                (int)($this->currentUser['id'] ?? 0)
            );
        } catch (\Exception $e) {
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }

    protected function handleException(\Throwable $e, string $context = ''): void
    {
        error_log("Exception in $context: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        $this->error('Внутренняя ошибка сервера', 500);
    }
}
