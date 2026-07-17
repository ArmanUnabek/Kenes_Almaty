<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';
require_once __DIR__ . '/../src/Services/WebhookService.php';

use App\ApiController;
use App\Services\WebhookService;

class WebhooksController extends ApiController
{
    private const ALLOWED_EVENTS = [
        'letter.created', 'letter.updated', 'letter.deleted',
        'member.created', 'member.updated', 'member.deleted',
    ];

    public function handle(): void
    {
        try {
            $this->requireRole(['admin', 'moderator']);

            switch ($_SERVER['REQUEST_METHOD']) {
                case 'GET':
                    $this->handleGet();
                    break;
                case 'POST':
                    $action = $this->getQueryParam('action', '');
                    if ($action === 'redeliver') {
                        $this->requireCsrf();
                        $this->handleRedeliver();
                    } elseif ($action === 'test') {
                        $this->requireCsrf();
                        $this->handleTest();
                    } else {
                        $this->requireCsrf();
                        $this->handleCreate();
                    }
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
            $this->handleException($e, 'WebhooksController');
        }
    }

    private function handleGet(): void
    {
        $id        = $this->getQueryParam('id');
        $deliveries = $this->getQueryParam('deliveries');
        $userId    = (int)$this->currentUser['id'];
        $isAdmin   = ($this->currentUser['role'] ?? '') === 'admin';
        $ownerClause = $isAdmin ? '' : 'AND w.user_id = ?';

        if ($id && $deliveries) {
            $stmt = $this->db->prepare("SELECT id FROM webhooks w WHERE w.id = ? $ownerClause");
            $params = $isAdmin ? [(int)$id] : [(int)$id, $userId];
            $stmt->execute($params);
            if (!$stmt->fetch()) {
                $this->error('Webhook не найден', 404);
            }
            $dStmt = $this->db->prepare(
                "SELECT id, event, response_code, response_body, delivered_at, created_at FROM webhook_deliveries WHERE webhook_id = ? ORDER BY id DESC LIMIT 100"
            );
            $dStmt->execute([(int)$id]);
            $this->json($dStmt->fetchAll());
            return;
        }

        if ($id) {
            $stmt = $this->db->prepare("SELECT * FROM webhooks w WHERE w.id = ? $ownerClause");
            $params = $isAdmin ? [(int)$id] : [(int)$id, $userId];
            $stmt->execute($params);
            $hook = $stmt->fetch();
            if (!$hook) {
                $this->error('Webhook не найден', 404);
            }
            $hook['events'] = is_string($hook['events']) ? json_decode($hook['events'], true) : $hook['events'];
            $this->json($hook);
            return;
        }

        if ($isAdmin) {
            $stmt = $this->db->prepare(
                "SELECT w.*, u.full_name AS owner_name FROM webhooks w JOIN users u ON w.user_id = u.id ORDER BY w.id DESC"
            );
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare("SELECT * FROM webhooks WHERE user_id = ? ORDER BY id DESC");
            $stmt->execute([$userId]);
        }
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['events'] = is_string($row['events']) ? json_decode($row['events'], true) : $row['events'];
        }
        $this->json($rows);
    }

    private function handleCreate(): void
    {
        $data = $this->getJsonInput() ?? [];
        if (empty($data['name'])) {
            $this->error('Поле name обязательно', 422);
        }
        if (empty($data['url'])) {
            $this->error('Поле url обязательно', 422);
        }
        if (empty($data['events']) || !is_array($data['events'])) {
            $this->error('Поле events обязательно и должно быть массивом', 422);
        }

        $events = array_values(array_filter(
            $data['events'],
            fn($e) => in_array($e, self::ALLOWED_EVENTS, true)
        ));
        if (empty($events)) {
            $this->error('Нет допустимых событий. Доступны: ' . implode(', ', self::ALLOWED_EVENTS), 422);
        }

        $secret = bin2hex(random_bytes(24));

        $stmt = $this->db->prepare(
            "INSERT INTO webhooks (user_id, name, url, secret, events, is_active) VALUES (?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            (int)$this->currentUser['id'],
            substr($data['name'], 0, 255),
            $data['url'],
            $secret,
            json_encode($events),
        ]);
        $id = (int)$this->db->lastInsertId();

        $this->json([
            'id'      => $id,
            'secret'  => $secret,
            'message' => 'Webhook создан. Сохраните секрет — он больше не будет показан.',
        ], 201);
    }

    private function handleUpdate(): void
    {
        $data  = $this->getJsonInput() ?? [];
        $id    = (int)($data['id'] ?? $this->getQueryParam('id') ?? 0);
        if (!$id) {
            $this->error('ID не указан', 400);
        }

        $userId  = (int)$this->currentUser['id'];
        $isAdmin = ($this->currentUser['role'] ?? '') === 'admin';

        $stmt = $this->db->prepare("SELECT user_id FROM webhooks WHERE id = ?");
        $stmt->execute([$id]);
        $hook = $stmt->fetch();
        if (!$hook) {
            $this->error('Webhook не найден', 404);
        }
        if (!$isAdmin && (int)$hook['user_id'] !== $userId) {
            $this->error('Доступ запрещён', 403);
        }

        $fields = [];
        $params = [];
        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $params[] = substr($data['name'], 0, 255);
        }
        if (isset($data['url'])) {
            $fields[] = 'url = ?';
            $params[] = $data['url'];
        }
        if (isset($data['events']) && is_array($data['events'])) {
            $fields[] = 'events = ?';
            $params[] = json_encode($data['events']);
        }
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = $data['is_active'] ? 1 : 0;
        }
        if (empty($fields)) {
            $this->error('Нет данных для обновления', 400);
        }

        $params[] = $id;
        $this->db->prepare("UPDATE webhooks SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        $this->json(['message' => 'Webhook обновлён']);
    }

    private function handleDelete(): void
    {
        $id      = (int)($this->getQueryParam('id') ?? 0);
        $userId  = (int)$this->currentUser['id'];
        $isAdmin = ($this->currentUser['role'] ?? '') === 'admin';

        if (!$id) {
            $this->error('ID не указан', 400);
        }

        $stmt = $this->db->prepare("SELECT user_id FROM webhooks WHERE id = ?");
        $stmt->execute([$id]);
        $hook = $stmt->fetch();
        if (!$hook) {
            $this->error('Webhook не найден', 404);
        }
        if (!$isAdmin && (int)$hook['user_id'] !== $userId) {
            $this->error('Доступ запрещён', 403);
        }

        $this->db->prepare("DELETE FROM webhooks WHERE id = ?")->execute([$id]);
        $this->json(['message' => 'Webhook удалён']);
    }

    private function handleTest(): void
    {
        $id = (int)($this->getQueryParam('id') ?? 0);
        if (!$id) {
            $this->error('ID не указан', 400);
        }

        $userId  = (int)$this->currentUser['id'];
        $isAdmin = ($this->currentUser['role'] ?? '') === 'admin';

        $stmt = $this->db->prepare("SELECT user_id FROM webhooks WHERE id = ?");
        $stmt->execute([$id]);
        $hook = $stmt->fetch();
        if (!$hook) {
            $this->error('Webhook не найден', 404);
        }
        if (!$isAdmin && (int)$hook['user_id'] !== $userId) {
            $this->error('Доступ запрещён', 403);
        }

        $result = WebhookService::testDispatch($this->db, $id);
        if (!$result['success']) {
            $this->error($result['error'], 400);
        }
        $this->json(['message' => 'Тестовое событие отправлено', 'delivery' => $result['delivery']]);
    }

    private function handleRedeliver(): void
    {
        $deliveryId = (int)($this->getQueryParam('delivery_id') ?? 0);
        if (!$deliveryId) {
            $this->error('delivery_id не указан', 400);
        }

        $stmt = $this->db->prepare(
            "SELECT wd.event, wd.payload, w.url, w.secret FROM webhook_deliveries wd JOIN webhooks w ON wd.webhook_id = w.id WHERE wd.id = ?"
        );
        $stmt->execute([$deliveryId]);
        $delivery = $stmt->fetch();
        if (!$delivery) {
            $this->error('Доставка не найдена', 404);
        }

        $payload = is_string($delivery['payload']) ? (json_decode($delivery['payload'], true) ?? []) : ($delivery['payload'] ?? []);
        WebhookService::dispatch($this->db, $delivery['event'], $payload);
        $this->json(['message' => 'Переотправка запущена']);
    }
}

(new WebhooksController())->handle();
