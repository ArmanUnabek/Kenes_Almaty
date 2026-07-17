<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';
require_once __DIR__ . '/../src/Services/ApiKeyService.php';

use App\ApiController;
use App\Services\ApiKeyService;

class ApiKeysController extends ApiController
{
    public function handle(): void
    {
        try {
            $this->requireRole(['admin']);

            switch ($_SERVER['REQUEST_METHOD']) {
                case 'GET':
                    $this->handleGet();
                    break;
                case 'POST':
                    $this->requireCsrf();
                    $this->handleCreate();
                    break;
                case 'DELETE':
                    $this->requireCsrf();
                    $this->handleDelete();
                    break;
                default:
                    $this->error('Метод не поддерживается', 405);
            }
        } catch (\Throwable $e) {
            $this->handleException($e, 'ApiKeysController');
        }
    }

    private function handleGet(): void
    {
        $userId = $this->getQueryParam('user_id');
        if ($userId) {
            $keys = ApiKeyService::listKeys($this->db, (int)$userId);
        } else {
            $stmt = $this->db->prepare(
                "SELECT ak.id, ak.user_id, ak.name, ak.permissions, ak.last_used_at, ak.expires_at, ak.is_active, ak.created_at, u.full_name AS owner_name
                 FROM api_keys ak JOIN users u ON ak.user_id = u.id ORDER BY ak.id DESC"
            );
            $stmt->execute();
            $keys = $stmt->fetchAll();
            foreach ($keys as &$key) {
                $key['permissions'] = $key['permissions'] ? json_decode($key['permissions'], true) : null;
            }
        }
        $this->json($keys);
    }

    private function handleCreate(): void
    {
        $data = $this->getJsonInput() ?? [];
        if (empty($data['name'])) {
            $this->error('Поле name обязательно', 422);
        }
        if (empty($data['user_id'])) {
            $this->error('Поле user_id обязательно', 422);
        }

        $userId = (int)$data['user_id'];
        $userStmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
        $userStmt->execute([$userId]);
        if (!$userStmt->fetch()) {
            $this->error('Пользователь не найден', 404);
        }

        $permissions = null;
        if (!empty($data['permissions']) && is_array($data['permissions'])) {
            $permissions = $data['permissions'];
        }

        $expiresIn = null;
        if (!empty($data['expires_in'])) {
            $expiresIn = (int)$data['expires_in'];
        }

        $result = ApiKeyService::createKey($this->db, $userId, $data['name'], $permissions, $expiresIn);

        $this->json([
            'id' => $result['id'],
            'key' => $result['key'],
            'name' => $result['name'],
            'message' => 'API-ключ создан. Сохраните ключ — он больше не будет показан.',
        ], 201);
    }

    private function handleDelete(): void
    {
        $id = (int)($this->getQueryParam('id') ?? 0);
        if (!$id) {
            $this->error('ID не указан', 400);
        }

        $revoked = ApiKeyService::revokeKey($this->db, $id);
        if (!$revoked) {
            $this->error('Ключ не найден', 404);
        }
        $this->json(['message' => 'API-ключ отозван']);
    }
}

(new ApiKeysController())->handle();
