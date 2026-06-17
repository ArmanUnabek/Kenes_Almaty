<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';
require_once __DIR__ . '/../src/Repositories/UserRepository.php';

use App\ApiController;
use App\Repositories\UserRepository;

class UsersController extends ApiController
{
    private UserRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new UserRepository($this->db);
    }

    public function handle(): void
    {
        try {
            if (!isAdmin()) {
                $this->error('Доступ только для супер-админа', 403);
            }

            $method = $_SERVER['REQUEST_METHOD'];
            $id = $this->getQueryParam('id');

            switch ($method) {
                case 'GET':
                    $this->handleGet($id);
                    break;
                case 'POST':
                    $this->requireCsrf();
                    $this->handleCreate();
                    break;
                case 'PUT':
                    $this->requireCsrf();
                    $this->handleUpdate();
                    break;
                case 'DELETE':
                    $this->requireCsrf();
                    $this->handleDelete();
                    break;
                default:
                    $this->error('Метод не поддерживается', 405);
            }
        } catch (\Throwable $e) {
            $this->handleException($e, 'UsersController');
        }
    }

    private function handleGet($id): void
    {
        if ($id) {
            $user = $this->repo->getById((int)$id);
            if (!$user) {
                $this->error('Пользователь не найден', 404);
            }
            $this->json($user);
        }

        $regionFilter = $this->getQueryParam('region_id');
        $page = max(1, (int)$this->getQueryParam('page', 1));
        $limit = max(1, min(200, (int)$this->getQueryParam('limit', 50)));
        $result = $this->repo->getAll($regionFilter ? (int)$regionFilter : null, $page, $limit);
        $this->paginated($result['items'], $result['total'], $page, $limit);
    }

    private function handleCreate(): void
    {
        $data = $this->getJsonInput() ?? [];
        $this->validateInput($data, [
            'username' => 'required|string|min:3|max:100',
            'email' => 'required|email|max:255',
            'full_name' => 'required|string|min:2|max:255',
            'password' => 'required|string|min:6|max:255',
            'role' => 'required|in:admin,moderator,viewer',
        ]);

        if ($this->repo->usernameExists($data['username'])) {
            $this->error('Пользователь с таким логином уже существует', 409);
        }

        $role = normalizeRole($data['role']);
        $regionId = $data['region_id'] ?? null;
        if ($role !== 'admin' && empty($regionId)) {
            $this->error('Для модератора и наблюдателя нужно указать регион', 400);
        }
        if ($role === 'admin') {
            $regionId = null;
        }

        $userId = $this->repo->create([
            'username' => $data['username'],
            'email' => $data['email'],
            'full_name' => $data['full_name'],
            'password' => $data['password'],
            'role' => $role,
            'region_id' => $regionId,
            'is_active' => true,
        ]);

        $this->logAction('users', $userId, 'CREATE', null, ['username' => $data['username'], 'role' => $role]);
        $this->json(['id' => $userId, 'message' => 'Пользователь создан'], 201);
    }

    private function handleUpdate(): void
    {
        $data = $this->getJsonInput() ?? [];
        $id = (int)($data['id'] ?? $this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }

        $existing = $this->repo->getById($id);
        if (!$existing) {
            $this->error('Пользователь не найден', 404);
        }

        if (!empty($data['username']) && $this->repo->usernameExists($data['username'], $id)) {
            $this->error('Пользователь с таким логином уже существует', 409);
        }

        if (!empty($data['role'])) {
            $data['role'] = normalizeRole($data['role']);
            if ($data['role'] === 'admin') {
                $data['region_id'] = null;
            }
        }

        if (!empty($data['password'])) {
            if (strlen($data['password']) < 6) {
                $this->error('Пароль должен быть не менее 6 символов', 400);
            }
        }

        $this->repo->update($id, $data);
        $this->logAction('users', $id, 'UPDATE', $existing, $data);
        $this->json(['message' => 'Пользователь обновлён']);
    }

    private function handleDelete(): void
    {
        $id = (int)($this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }
        if ($id === (int)($this->currentUser['id'] ?? 0)) {
            $this->error('Нельзя деактивировать собственный аккаунт', 400);
        }

        $existing = $this->repo->getById($id);
        if (!$existing) {
            $this->error('Пользователь не найден', 404);
        }

        $this->repo->deactivate($id);
        $this->logAction('users', $id, 'DELETE', $existing, null);
        $this->json(['message' => 'Пользователь деактивирован']);
    }
}

$controller = new UsersController();
$controller->handle();
