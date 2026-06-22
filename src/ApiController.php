<?php

namespace App;

use App\Middleware\CsrfMiddleware;
use App\Services\AuditLogger;

abstract class ApiController
{
    protected \PDO $db;
    protected int $JSON_FLAGS = JSON_ENCODE_FLAGS;
    protected ?array $currentUser = null;

    public function __construct()
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        if (function_exists('configureSessionCookie')) {
            configureSessionCookie();
        }
        if (session_status() === \PHP_SESSION_NONE) {
            session_start();
        }

        $this->db = getDBConnection();
        CsrfMiddleware::init();
        $this->currentUser = getCurrentUser();
    }

    protected function json($data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data, $this->JSON_FLAGS);
        exit;
    }

    protected function error(string $message, int $code = 400): void
    {
        $this->json(['error' => $message], $code);
    }

    protected function validationError(array $errors, int $code = 422): void
    {
        $this->json([
            'error' => 'Ошибка валидации',
            'errors' => $errors,
        ], $code);
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
                'pages' => (int)ceil($total / max(1, $limit)),
            ],
        ]);
    }

    protected function requireAuth(): void
    {
        checkAuth();
        $this->currentUser = getCurrentUser();
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

    protected function validateInput(array $data, array $rules): array
    {
        $validator = new Validator();
        if (!$validator->validate($data, $rules)) {
            $this->validationError($validator->getErrors());
        }
        return $data;
    }

    protected function getJsonInput(): ?array
    {
        $input = file_get_contents('php://input');
        $decoded = json_decode($input, true);
        return is_array($decoded) ? $decoded : null;
    }

    protected function getQueryParam(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    protected function getPostParam(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    protected function getCurrentRegionId(): ?int
    {
        return getCurrentRegionId();
    }

    protected function resolveRegionIdForRead(): ?int
    {
        return resolveRegionIdForRead();
    }

    protected function canAccessRegion(int $regionId): bool
    {
        return canAccessRegion($regionId);
    }

    protected function requireRegionAccess(int $regionId): void
    {
        if (!$this->canAccessRegion($regionId)) {
            $this->error('Доступ к данным этого региона запрещён', 403);
        }
    }

    protected function logAction(string $table, int $entityId, string $action, ?array $oldData = null, ?array $newData = null): void
    {
        try {
            AuditLogger::log(
                $this->db,
                $table,
                $entityId,
                $action,
                \App\Services\AuditSanitizer::sanitize($oldData),
                \App\Services\AuditSanitizer::sanitize($newData),
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
