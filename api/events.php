<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';
require_once __DIR__ . '/../src/Repositories/EventRepository.php';

use App\ApiController;
use App\Repositories\EventRepository;
use App\Services\AuditSanitizer;
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
                case 'PATCH':
                    $this->requireWriteAccess();
                    $this->requireCsrf();
                    $this->handlePatch();
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
            assertEventRegionAccess($event);
            $this->json($event);
        }

        $page = max(1, (int)$this->getQueryParam('page', 1));
        $limit = max(1, min(500, (int)$this->getQueryParam('limit', 30)));
        $result = $this->repo->getAll($this->resolveRegionIdForRead(), $page, $limit);
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

    private function requireEventAccess(int $id): array
    {
        $event = $this->repo->getById($id);
        if (!$event) {
            $this->error('Мероприятие не найдено', 404);
        }
        assertEventRegionAccess($event);
        return $event;
    }

    private function handleCreate(): void
    {
        $data = $this->getJsonInput() ?? [];
        $this->validateEventPayload($data);

        $regionId = resolveRegionIdForWrite(
            isset($data['region_id']) ? (int)$data['region_id'] : null
        );
        $createdBy = $this->currentUser['id'] ?? null;

        $eventId = $this->repo->create($data, $regionId, $createdBy);

        AuditLogger::log($this->db, 'events', $eventId, 'CREATE', null, AuditSanitizer::sanitize($data), (int)($createdBy ?? 0));
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
        $this->requireEventAccess($id);
        $this->validateEventPayload($data);

        $this->repo->update($id, $data);

        AuditLogger::log($this->db, 'events', $id, 'UPDATE', null, AuditSanitizer::sanitize($data), (int)($this->currentUser['id'] ?? 0));
        (new FileCache())->forgetPrefix('kpi:');
        $this->json(['message' => 'Мероприятие обновлено']);
    }

    /**
     * PATCH: строго ограниченное частичное обновление мероприятия.
     * Используется, в частности, для drag-to-reschedule в календаре —
     * позволяет менять только event_date, не затрагивая остальные поля.
     */
    private function handlePatch(): void
    {
        $data = $this->getJsonInput() ?? [];
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }
        $this->requireEventAccess($id);

        $allowed = ['event_date'];
        $patch = array_intersect_key($data, array_flip($allowed));
        if (empty($patch)) {
            $this->error('Нет допустимых полей для обновления', 400);
        }

        if (array_key_exists('event_date', $patch)) {
            $date = (string)$patch['event_date'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate(
                (int)substr($date, 5, 2),
                (int)substr($date, 8, 2),
                (int)substr($date, 0, 4)
            )) {
                $this->error('Некорректная дата (ожидается YYYY-MM-DD)', 422);
            }
        }

        $this->repo->patch($id, $patch);

        AuditLogger::log($this->db, 'events', $id, 'UPDATE', null, AuditSanitizer::sanitize($patch), (int)($this->currentUser['id'] ?? 0));
        (new FileCache())->forgetPrefix('kpi:');
        $this->json(['message' => 'Мероприятие перенесено']);
    }

    private function handleDelete(): void
    {
        $id = (int)($this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }
        $this->requireEventAccess($id);

        $this->repo->delete($id);
        AuditLogger::log($this->db, 'events', $id, 'DELETE', ['id' => $id], null, (int)($this->currentUser['id'] ?? 0));
        (new FileCache())->forgetPrefix('kpi:');
        $this->json(['message' => 'Мероприятие удалено']);
    }
}

(new EventsController())->handle();
