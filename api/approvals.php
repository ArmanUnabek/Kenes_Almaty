<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';

use App\ApiController;
use App\Services\ApprovalService;

class ApprovalsController extends ApiController
{
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
                    $this->handlePost();
                    break;
                default:
                    $this->error('Метод не поддерживается', 405);
            }
        } catch (\Throwable $e) {
            $this->handleException($e, 'ApprovalsController');
        }
    }

    private function handleGet(): void
    {
        $action = $this->getQueryParam('action', '');

        if ($action === 'templates') {
            $regionId = $this->resolveRegionIdForRead();
            $this->json(['templates' => ApprovalService::getTemplates($this->db, $regionId ?: null)]);
            return;
        }

        $letterType = $this->getQueryParam('letter_type');
        $letterId = $this->getQueryParam('letter_id');

        if (!$letterType || !$letterId) {
            $this->error('Требуются параметры letter_type и letter_id', 400);
        }

        $chain = ApprovalService::getChainStatus($letterType, (int)$letterId, $this->db);
        if (!$chain) {
            $this->json(['chain' => null, 'message' => 'Цепочка согласования не найдена']);
        }

        $this->json(['chain' => $chain]);
    }

    private function handlePost(): void
    {
        $action = $this->getQueryParam('action', '');
        $data = $this->getJsonInput() ?? [];

        switch ($action) {
            case 'create':
                $this->handleCreate($data);
                break;
            case 'approve':
                $this->handleApprove($data);
                break;
            case 'reject':
                $this->handleReject($data);
                break;
            case 'create_template':
                $this->handleCreateTemplate($data);
                break;
            case 'delete_template':
                $this->handleDeleteTemplate();
                break;
            default:
                $this->error('Неизвестное действие. Допустимые: create, approve, reject, create_template, delete_template', 400);
        }
    }

    private function handleCreateTemplate(array $data): void
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            $this->error('Поле name обязательно', 400);
        }
        $steps = $data['steps'] ?? [];
        if (empty($steps)) {
            $this->error('Необходимо указать хотя бы один шаг', 422);
        }
        try {
            $id = ApprovalService::createTemplate($this->db, [
                'name'      => $name,
                'region_id' => isset($data['region_id']) ? (int)$data['region_id'] : null,
                'steps'     => $steps,
            ]);
            $this->logAction('approval_templates', $id, 'CREATE', null, $data);
            $this->json(['id' => $id, 'message' => 'Шаблон создан'], 201);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
        }
    }

    private function handleDeleteTemplate(): void
    {
        $id = (int)$this->getQueryParam('id', 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }
        $stmt = $this->db->prepare('SELECT id FROM approval_templates WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) {
            $this->error('Шаблон не найден', 404);
        }
        $this->db->prepare('DELETE FROM approval_templates WHERE id = ?')->execute([$id]);
        $this->logAction('approval_templates', $id, 'DELETE', null, null);
        $this->json(['message' => 'Шаблон удалён']);
    }

    private function handleCreate(array $data): void
    {
        $letterType = $data['letter_type'] ?? '';
        $letterId = (int)($data['letter_id'] ?? 0);
        $templateId = $data['template_id'] ?? null;
        $steps = $data['steps'] ?? [];

        if (!$letterType || $letterId <= 0) {
            $this->validationError(['letter_type' => 'Обязательное поле', 'letter_id' => 'Обязательное поле']);
        }

        try {
            if ($templateId) {
                $chainId = ApprovalService::createChainFromTemplate($this->db, $letterType, $letterId, (int)$templateId);
            } else {
                if (empty($steps)) {
                    $this->validationError(['steps' => 'Необходимо указать шаги или template_id']);
                }
                $chainId = ApprovalService::createChain($this->db, $letterType, $letterId, $steps);
            }

            $this->logAction('approval_chains', $chainId, 'CREATE', null, $data);
            $this->json(['id' => $chainId, 'message' => 'Цепочка согласования создана'], 201);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
        }
    }

    private function handleApprove(array $data): void
    {
        $this->processDecision($data, 'approve');
    }

    private function handleReject(array $data): void
    {
        $this->processDecision($data, 'reject');
    }

    private function processDecision(array $data, string $action): void
    {
        $letterType = $data['letter_type'] ?? '';
        $letterId = (int)($data['letter_id'] ?? 0);
        $notes = $data['notes'] ?? null;

        if (!$letterType || $letterId <= 0) {
            $this->validationError(['letter_type' => 'Обязательное поле', 'letter_id' => 'Обязательное поле']);
        }

        $userId = (int)($this->getCurrentUser()['id'] ?? 0);
        if (!$userId) {
            $this->error('Не удалось определить пользователя', 401);
        }

        try {
            $result = $action === 'approve'
                ? ApprovalService::approveStep($this->db, $letterType, $letterId, $userId, $notes)
                : ApprovalService::rejectStep($this->db, $letterType, $letterId, $userId, $notes);

            $this->logAction('approval_chains', 0, strtoupper($action), null, $data);
            $this->json($result);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
        }
    }
}

$controller = new ApprovalsController();
$controller->handle();
