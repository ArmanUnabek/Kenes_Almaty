<?php
/**
 * Базовый класс для всех API контроллеров.
 * Содержит общую логику для обработки ошибок, JSON-ответов и CSRF-защиты.
 */

namespace App;

use App\Middleware\CsrfMiddleware;

abstract class ApiController
{
    /**
     * Соединение с БД
     */
    protected \PDO $db;

    /**
     * Флаги для JSON-кодирования
     */
    protected int $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * HTTP код ответа по умолчанию
     */
    protected int $httpCode = 200;

    /**
     * Инициализация контроллера
     */
    public function __construct()
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        $this->db = getDBConnection();
        CsrfMiddleware::init();
    }

    /**
     * Отправить JSON-ответ
     *
     * @param mixed $data Данные для отправки
     * @param int $httpCode HTTP код ответа (по умолчанию 200)
     * @return void
     */
    protected function json($data, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        $encoded = json_encode($data, $this->jsonFlags);
        if ($encoded === false) {
            $encoded = json_encode(['error' => 'Ошибка кодирования JSON'], $this->jsonFlags);
        }
        echo $encoded;
        exit;
    }

    /**
     * Отправить ошибку
     *
     * @param string $message Сообщение об ошибке
     * @param int $httpCode HTTP код ошибки (по умолчанию 400)
     * @return void
     */
    protected function error(string $message, int $httpCode = 400): void
    {
        $this->json(['error' => $message], $httpCode);
    }

    /**
     * Отправить ошибки валидации
     *
     * @param array $errors Ошибки валидации
     * @return void
     */
    protected function validationError(array $errors): void
    {
        $this->json([
            'error' => 'Ошибка валидации',
            'errors' => $errors
        ], 422);
    }

    /**
     * Отправить успешный ответ
     *
     * @param mixed $data Данные для отправки
     * @param string|null $message Сообщение успеха
     * @return void
     */
    protected function success($data = null, ?string $message = null): void
    {
        $response = [
            'success' => true,
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        $this->json($response, 200);
    }

    /**
     * Отправить данные с пагинацией
     *
     * @param array $data Данные
     * @param int $total Всего записей
     * @param int $page Текущая страница
     * @param int $perPage Записей на странице
     * @return void
     */
    protected function paginated(array $data, int $total, int $page = 1, int $perPage = 30): void
    {
        $this->json([
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int)ceil($total / $perPage),
            ]
        ], 200);
    }

    /**
     * Получить параметры пагинации из запроса
     *
     * @param int $defaultPerPage Записей на странице по умолчанию
     * @param int $maxPerPage Максимум записей на странице
     * @return array ['page' => int, 'per_page' => int, 'offset' => int]
     */
    protected function getPagination(int $defaultPerPage = 30, int $maxPerPage = 100): array
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min($maxPerPage, max(1, (int)($_GET['per_page'] ?? $defaultPerPage)));
        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * Получить JSON-данные из тела запроса
     *
     * @return array
     */
    protected function getJsonBody(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true) ?? [];
        return is_array($data) ? $data : [];
    }

    /**
     * Проверить метод запроса
     *
     * @param string ...$methods Допустимые методы
     * @return bool
     */
    protected function checkMethod(...$methods): bool
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        return in_array($method, $methods, true);
    }

    /**
     * Требовать определённый метод запроса
     *
     * @param string ...$methods Требуемые методы
     * @return void
     */
    protected function requireMethod(...$methods): void
    {
        if (!$this->checkMethod(...$methods)) {
            $this->error('Метод запроса не поддерживается', 405);
        }
    }

    /**
     * Получить ID из параметров запроса
     *
     * @param string $paramName Имя параметра (по умолчанию 'id')
     * @return int
     */
    protected function getId(string $paramName = 'id'): int
    {
        $id = (int)($_GET[$paramName] ?? 0);
        if ($id <= 0) {
            $this->error('Неправильный ID', 400);
        }
        return $id;
    }

    /**
     * Логировать действие
     *
     * @param string $action Действие
     * @param array $context Контекст логирования
     * @return void
     */
    protected function log(string $action, array $context = []): void
    {
        Logger::info($action, $context);
    }
}
