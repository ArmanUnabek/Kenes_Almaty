<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';
require_once __DIR__ . '/../src/Repositories/MemberRepository.php';

use App\ApiController;
use App\Repositories\MemberRepository;

class MembersController extends ApiController
{
    private MemberRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new MemberRepository($this->db);
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
            $this->handleException($e, 'MembersController');
        }
    }

    private function handleGet($id, ?int $regionId): void
    {
        $commissionId = $this->getQueryParam('commission_id');
        $page = max(1, (int)$this->getQueryParam('page', 1));
        $limit = max(1, min(200, (int)$this->getQueryParam('limit', 50)));

        if ($id) {
            $member = $this->repo->getById((int)$id, $regionId);
            if (!$member) {
                $this->error('Член ОС не найден', 404);
            }
            $this->json($member);
        }

        if ($commissionId) {
            $members = $this->repo->getByCommission((int)$commissionId, $regionId);
            $this->json($members);
        }

        $hasPagination = isset($_GET['page']) || isset($_GET['limit']);
        $result = $this->repo->getAll($page, $limit, $regionId);
        if ($hasPagination) {
            $this->paginated($result['items'], $result['total'], $page, $limit);
        }
        $this->json($result['items']);
    }

    private function buildMemberRules(array $data): array
    {
        $rules = [
            'full_name' => 'required|string|min:2|max:255',
            'position' => 'string|max:255',
            'organization' => 'string|max:255',
            'status' => 'in:active,inactive',
        ];
        if (!empty($data['email'])) {
            $rules['email'] = 'email|max:255';
        }
        if (!empty($data['phone'])) {
            $rules['phone'] = 'phone|max:50';
        }
        return $rules;
    }

    private function handleCreate(?int $regionId): void
    {
        $data = $this->getJsonInput() ?? [];
        $this->validateInput($data, $this->buildMemberRules($data));

        $targetRegion = resolveRegionIdForWrite(isset($data['region_id']) ? (int)$data['region_id'] : null);
        $this->requireRegionAccess($targetRegion);
        $data['region_id'] = $targetRegion;

        $memberId = $this->repo->create($data);
        $this->logAction('os_members', $memberId, 'CREATE', null, $data);
        $this->json(['id' => $memberId, 'message' => 'Член ОС успешно создан'], 201);
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
            $this->error('Член ОС не найден', 404);
        }

        $this->validateInput($data, $this->buildMemberRules($data));

        $this->repo->update($id, $data, $regionId);
        $this->logAction('os_members', $id, 'UPDATE', $existing, $data);
        $this->json(['message' => 'Член ОС успешно обновлён']);
    }

    private function handleDelete(?int $regionId): void
    {
        $id = (int)($this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }

        $existing = $this->repo->getById($id, $regionId);
        if (!$existing) {
            $this->error('Член ОС не найден', 404);
        }

        $this->repo->delete($id, $regionId);
        $this->logAction('os_members', $id, 'DELETE', $existing, null);
        $this->json(['message' => 'Член ОС успешно удалён']);
    }
}

$controller = new MembersController();
$controller->handle();
