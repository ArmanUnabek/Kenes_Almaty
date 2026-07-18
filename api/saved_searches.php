<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';

use App\ApiController;
use App\Middleware\CsrfMiddleware;

class SavedSearchesController extends ApiController
{
    public function handle(): void
    {
        try {
            $this->requireAuth();

            switch ($_SERVER['REQUEST_METHOD']) {
                case 'GET':
                    $this->handleList();
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
            $this->handleException($e, 'SavedSearchesController');
        }
    }

    private function handleList(): void
    {
        $userId = (int)($this->currentUser['id'] ?? 0);
        $stmt   = $this->db->prepare(
            'SELECT id, name, params, created_at FROM saved_searches WHERE user_id = ? ORDER BY created_at DESC LIMIT 100'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            if (is_string($row['params'])) {
                $decoded = json_decode($row['params'], true);
                $row['params'] = is_array($decoded) ? $decoded : [];
            }
        }
        unset($row);

        $this->json(['items' => $rows]);
    }

    private function handleCreate(): void
    {
        $userId = (int)($this->currentUser['id'] ?? 0);
        $data   = $this->getJsonInput() ?? [];

        $name   = trim((string)($data['name'] ?? ''));
        $params = $data['params'] ?? null;

        if ($name === '') {
            $this->error('Укажите название сохранённого поиска', 422);
        }
        if (!is_array($params)) {
            $this->error('Параметры поиска обязательны', 422);
        }

        // Enforce limit: max 50 saved searches per user
        $cntStmt = $this->db->prepare('SELECT COUNT(*) FROM saved_searches WHERE user_id = ?');
        $cntStmt->execute([$userId]);
        if ((int)$cntStmt->fetchColumn() >= 50) {
            $this->error('Достигнут лимит сохранённых поисков (50)', 422);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO saved_searches (user_id, name, params) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $name, json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $newId = (int)$this->db->lastInsertId();

        $this->json([
            'success' => true,
            'id'      => $newId,
            'name'    => $name,
            'params'  => $params,
        ], 201);
    }

    private function handleDelete(): void
    {
        $userId = (int)($this->currentUser['id'] ?? 0);
        $id     = (int)($this->getQueryParam('id', 0));

        if ($id <= 0) {
            $this->error('Укажите id', 400);
        }

        // Ownership check
        $stmt = $this->db->prepare('SELECT user_id FROM saved_searches WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            $this->error('Сохранённый поиск не найден', 404);
        }
        if ((int)$row['user_id'] !== $userId) {
            $this->error('Нет доступа', 403);
        }

        $this->db->prepare('DELETE FROM saved_searches WHERE id = ?')->execute([$id]);
        $this->json(['success' => true]);
    }

    // Schema note: saved_searches is defined in deploy_database.sql and
    // migrations/2026_07_18_endpoint_tables.sql. Runtime self-healing DDL removed.
}

$controller = new SavedSearchesController();
$controller->handle();
