<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';
require_once __DIR__ . '/../src/Repositories/EventRepository.php';

use App\ApiController;
use App\Repositories\EventRepository;
use App\Services\AuditLogger;
use App\Services\FileCache;
use App\Services\LetterService;

class EventsController extends ApiController
{
    private EventRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new EventRepository($this->db);
    }

    public function handle(): void
    {
        try {
            $this->requireAuth();
            switch ($_SERVER['REQUEST_METHOD']) {
                case 'GET':
                    $this->handleGet();
                    break;
                case 'POST':
                    $this->requireWriteAccess();
                    $this->requireCsrf();
                    $this->handleCreate();
                    break;
                case 'PUT':
                    $this->requireWriteAccess();
                    $this->requireCsrf();
                    $this->handleUpdate();
                    break;
                case 'DELETE':
                    $this->requireDeleteAccess();
                    $this->requireCsrf();
                    $this->handleDelete();
                    break;
                default:
                    $this->error('Метод не поддерживается', 405);
            }
        } catch (\Throwable $e) {
            $this->handleException($e, 'EventsController');
        }
    }

    private function handleGet(): void
    {
        $id = $this->getQueryParam('id');
        if ($id) {
            $event = $this->repo->getById((int)$id);
            if (!$event) {
                $this->error('Мероприятие не найдено', 404);
            }
            $this->json($event);
        }

        $page = max(1, (int)$this->getQueryParam('page', 1));
        $limit = max(1, min(500, (int)$this->getQueryParam('limit', 30)));
        $result = $this->repo->getAll($this->getCurrentRegionId(), $page, $limit);
        $this->paginated($result['items'], $result['total'], $page, $limit);
    }

    private function validateEventPayload(array $data): void
    {
        try {
            LetterService::validateEvent($data);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        }
    }

    private function handleCreate(): void
    {
        $data = $this->getJsonInput() ?? [];
        $this->validateEventPayload($data);

        $regionId = (int)($data['region_id'] ?? $this->currentUser['region_id'] ?? 0) ?: null;
        $createdBy = $this->currentUser['id'] ?? null;

        try {
            $this->db->beginTransaction();
            $eventId = $this->repo->create($data, $regionId, $createdBy);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        pusherTrigger('council-events', 'events-updated', ['action' => 'create', 'id' => $eventId]);
        AuditLogger::log($this->db, 'events', $eventId, 'CREATE', null, $data, (int)($createdBy ?? 0));
        (new FileCache())->forgetPrefix('kpi:');
        $this->json(['id' => $eventId, 'message' => 'Мероприятие добавлено'], 201);
    }

    private function handleUpdate(): void
    {
        $data = $this->getJsonInput() ?? [];
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }
        $this->validateEventPayload($data);

        try {
            $this->db->beginTransaction();
            $this->repo->update($id, $data);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        pusherTrigger('council-events', 'events-updated', ['action' => 'update', 'id' => $id]);
        AuditLogger::log($this->db, 'events', $id, 'UPDATE', null, $data, (int)($this->currentUser['id'] ?? 0));
        (new FileCache())->forgetPrefix('kpi:');
        $this->json(['message' => 'Мероприятие обновлено']);
    }

    private function handleDelete(): void
    {
        $id = (int)($this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }

        $this->repo->delete($id);
        pusherTrigger('council-events', 'events-updated', ['action' => 'delete', 'id' => $id]);
        AuditLogger::log($this->db, 'events', $id, 'DELETE', ['id' => $id], null, (int)($this->currentUser['id'] ?? 0));
        (new FileCache())->forgetPrefix('kpi:');
        $this->json(['message' => 'Мероприятие удалено']);
    }
}

(new EventsController())->handle();
