<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';

use App\ApiController;
use App\Services\LetterClassifier;

class CategoriesController extends ApiController
{
    private LetterClassifier $classifier;

    public function __construct()
    {
        parent::__construct();
        $this->classifier = new LetterClassifier($this->db);
        LetterClassifier::ensureTable($this->db);
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
            $this->handleException($e, 'CategoriesController');
        }
    }

    private function handleGet(): void
    {
        $id = $this->getQueryParam('id');
        if ($id) {
            $stmt = $this->db->prepare('SELECT * FROM letter_categories WHERE id = ?');
            $stmt->execute([(int)$id]);
            $cat = $stmt->fetch();
            if (!$cat) {
                $this->error('Категория не найдена', 404);
            }
            $this->json($cat);
        }

        $stmt = $this->db->query('SELECT * FROM letter_categories ORDER BY sort_order, id');
        $this->json($stmt->fetchAll());
    }

    private function handleCreate(): void
    {
        $data = $this->getJsonInput() ?? [];
        $this->validateInput($data, [
            'name' => 'required|string|min:2|max:255',
        ]);

        $commissionId = !empty($data['commission_id']) ? (int)$data['commission_id'] : null;
        $keywords = $data['keywords'] ?? null;
        if (is_array($keywords)) {
            $keywords = json_encode($keywords, JSON_ENCODE_FLAGS);
        }

        $stmt = $this->db->prepare('
            INSERT INTO letter_categories (name, commission_id, keywords, sort_order)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['name'],
            $commissionId,
            $keywords,
            (int)($data['sort_order'] ?? 0),
        ]);

        $id = (int)$this->db->lastInsertId();
        $this->logAction('letter_categories', $id, 'CREATE', null, $data);
        $this->json(['id' => $id, 'message' => 'Категория успешно создана'], 201);
    }

    private function handleUpdate(): void
    {
        $data = $this->getJsonInput() ?? [];
        $id = (int)($data['id'] ?? $this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }

        $stmt = $this->db->prepare('SELECT * FROM letter_categories WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            $this->error('Категория не найдена', 404);
        }

        $rules = ['name' => 'required|string|min:2|max:255'];
        $this->validateInput($data, $rules);

        $commissionId = array_key_exists('commission_id', $data)
            ? ($data['commission_id'] ? (int)$data['commission_id'] : null)
            : $existing['commission_id'];
        $keywords = $data['keywords'] ?? $existing['keywords'];
        if (is_array($keywords)) {
            $keywords = json_encode($keywords, JSON_ENCODE_FLAGS);
        }
        $sortOrder = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : $existing['sort_order'];

        $upd = $this->db->prepare('
            UPDATE letter_categories SET name = ?, commission_id = ?, keywords = ?, sort_order = ?
            WHERE id = ?
        ');
        $upd->execute([$data['name'], $commissionId, $keywords, $sortOrder, $id]);

        $this->logAction('letter_categories', $id, 'UPDATE', $existing, $data);
        $this->json(['message' => 'Категория успешно обновлена']);
    }

    private function handleDelete(): void
    {
        $id = (int)($this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }

        $stmt = $this->db->prepare('SELECT * FROM letter_categories WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            $this->error('Категория не найдена', 404);
        }

        $this->db->prepare('DELETE FROM letter_categories WHERE id = ?')->execute([$id]);
        $this->logAction('letter_categories', $id, 'DELETE', $existing, null);
        $this->json(['message' => 'Категория успешно удалена']);
    }
}

$controller = new CategoriesController();
$controller->handle();
