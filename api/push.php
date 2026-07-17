<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

use App\ApiController;
use App\Middleware\CsrfMiddleware;
use App\Services\PushService;
use App\Services\PushNotificationService;

class PushController extends ApiController
{
    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            switch ($method) {
                case 'GET':
                    $this->handleGet();
                    break;
                case 'POST':
                    $this->handleSubscribe();
                    break;
                case 'DELETE':
                    $this->handleUnsubscribe();
                    break;
                default:
                    $this->error('Метод не поддерживается', 405);
            }
        } catch (\Throwable $e) {
            $this->handleException($e, 'push');
        }
    }

    private function handleGet(): void
    {
        $this->json([
            'vapid_public_key' => VAPID_PUBLIC_KEY,
            'enabled' => PushNotificationService::isConfigured(),
        ]);
    }

    private function handleSubscribe(): void
    {
        $this->requireAuth();
        CsrfMiddleware::requireVerification();

        $data = $this->getJsonInput();
        if (!$data) {
            $this->error('Неверный JSON', 400);
        }

        $endpoint = trim($data['endpoint'] ?? '');
        $keys = $data['keys'] ?? [];
        $p256dh = trim($keys['p256dh'] ?? '');
        $auth = trim($keys['auth'] ?? '');

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            $this->error('endpoint, keys.p256dh и keys.auth обязательны', 400);
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        PushService::subscribe($this->db, $userId, $endpoint, $p256dh, $auth);

        $this->success(null, 'Подписка оформлена');
    }

    private function handleUnsubscribe(): void
    {
        $this->requireAuth();
        CsrfMiddleware::requireVerification();

        $data = $this->getJsonInput();
        if (!$data) {
            $this->error('Неверный JSON', 400);
        }

        $endpoint = trim($data['endpoint'] ?? '');
        if ($endpoint === '') {
            $this->error('endpoint обязателен', 400);
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        PushService::unsubscribe($this->db, $userId, $endpoint);

        $this->success(null, 'Подписка удалена');
    }
}

$controller = new PushController();
$controller->handle();
