<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';
require_once __DIR__ . '/../src/Repositories/CommissionRepository.php';

use App\ApiController;
use App\Repositories\CommissionRepository;

class CommissionsController extends ApiController
{
    private CommissionRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new CommissionRepository($this->db);
    }

    public function handle(): void
    {
        try {
            $this->requireAuth();
            $method = $_SERVER['REQUEST_METHOD'];
            $id = $this->getQueryParam('id');
            $regionId = $this->resolveRegionIdForRead();

            switch ($method) {
                case 'GET':
                    $this->handleGet($id, $regionId);
                    break;
                case 'POST':
                    $this->requireWriteAccess();
                    $this->requireCsrf();
                    $this->handleCreate($regionId);
                    break;
                case 'PUT':
                    $this->requireWriteAccess();
                    $this->requireCsrf();
                    $this->handleUpdate($regionId);
                    break;
                case 'DELETE':
                    $this->requireDeleteAccess();
                    $this->requireCsrf();
                    $this->handleDelete($regionId);
                    break;
                default:
                    $this->error('Метод не поддерживается', 405);
            }
        } catch (\Throwable $e) {
            $this->handleException($e, 'CommissionsController');
        }
    }

    private function handleGet($id, ?int $regionId): void
    {
        $page = max(1, (int)$this->getQueryParam('page', 1));
        $limit = max(1, min(200, (int)$this->getQueryParam('limit', 50)));

        if ($id) {
            $commission = $this->repo->getById((int)$id, $regionId);
            if (!$commission) {
                $this->error('Комиссия не найдена', 404);
            }
            $this->json($commission);
        }

        $hasPagination = isset($_GET['page']) || isset($_GET['limit']);
        $result = $this->repo->getAll($regionId, $page, $limit);
        if ($hasPagination) {
            $this->paginated($result['items'], $result['total'], $page, $limit);
        }
        $this->json($result['items']);
    }

    private function handleCreate(?int $regionId): void
    {
        $data = $this->getJsonInput() ?? [];
        $rules = ['name' => 'required|string|min:2|max:255'];
        if (!empty($data['color'])) {
            // Только hex-цвет — иначе значение попадает в style="..." (XSS).
            $rules['color'] = 'string|max:20|regex:/^#[0-9A-Fa-f]{3,8}$/';
        }
        $this->validateInput($data, $rules);

        $targetRegion = (int)($data['region_id'] ?? $regionId ?? 1);
        $this->requireRegionAccess($targetRegion);

        $commissionId = $this->repo->create([
            'region_id' => $targetRegion,
            'name' => $data['name'],
            'color' => $data['color'] ?? '#6c757d',
            'sort_order' => (int)($data['sort_order'] ?? 0),
        ]);
        $this->logAction('commissions', $commissionId, 'CREATE', null, $data);
        $this->json(['id' => $commissionId, 'message' => 'Комиссия успешно создана'], 201);
    }

    private function handleUpdate(?int $regionId): void
    {
        $data = $this->getJsonInput() ?? [];
        $id = (int)($data['id'] ?? $this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }

        $existing = $this->repo->getById($id, $regionId);
        if (!$existing) {
            $this->error('Комиссия не найдена', 404);
        }

        $rules = ['name' => 'required|string|min:2|max:255'];
        if (!empty($data['color'])) {
            $rules['color'] = 'string|max:20|regex:/^#[0-9A-Fa-f]{3,8}$/';
        }
        $this->validateInput($data, $rules);

        $this->repo->update($id, $data, $regionId);
        $this->logAction('commissions', $id, 'UPDATE', $existing, $data);
        $this->json(['message' => 'Комиссия успешно обновлена']);
    }

    private function handleDelete(?int $regionId): void
    {
        $id = (int)($this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }

        $existing = $this->repo->getById($id, $regionId);
        if (!$existing) {
            $this->error('Комиссия не найдена', 404);
        }

        $memberCount = $this->repo->countMembers($id);
        if ($memberCount > 0) {
            $this->error('Нельзя удалить комиссию с назначенными членами ОС', 409);
        }

        $this->repo->delete($id, $regionId);
        $this->logAction('commissions', $id, 'DELETE', $existing, null);
        $this->json(['message' => 'Комиссия успешно удалена']);
    }
}

$controller = new CommissionsController();
$controller->handle();
