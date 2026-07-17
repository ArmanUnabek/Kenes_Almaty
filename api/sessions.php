<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';

use App\ApiController;
use App\Middleware\CsrfMiddleware;
use App\Services\SessionManager;

class SessionController extends ApiController
{
    private SessionManager $sessionManager;

    public function __construct()
    {
        parent::__construct();
        $this->sessionManager = new SessionManager($this->db);
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'GET') {
            $this->handleList();
        } elseif ($method === 'POST') {
            $this->handleAction();
        } else {
            $this->error('Метод не поддерживается', 405);
        }
    }

    private function handleList(): void
    {
        $this->requireAuth();
        $user = $this->getCurrentUser();
        $userId = (int) $user['id'];

        $sessions = $this->sessionManager->getActiveSessions($userId);
        $out = [];
        foreach ($sessions as $s) {
            $out[] = [
                'id'          => substr($s['id'], 0, 8) . '…',
                'token'       => hash('sha256', $s['id']),
                'ip_address'  => $s['ip_address'],
                'user_agent'  => $s['user_agent'],
                'last_active' => $s['last_active'],
                'created_at'  => $s['created_at'],
                'is_current'  => (bool) $s['is_current'],
            ];
        }
        $this->json(['sessions' => $out]);
    }

    private function handleAction(): void
    {
        $this->requireAuth();
        CsrfMiddleware::requireVerification();

        $user = $this->getCurrentUser();
        $userId = (int) $user['id'];

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $input['action'] ?? ($_POST['action'] ?? '');

        if ($action === 'terminate_all') {
            $count = $this->sessionManager->terminateAllSessions($userId, session_id());
            $this->json(['success' => true, 'message' => "Завершено сессий: {$count}"]);
        }

        if ($action !== 'terminate') {
            $this->error('Неизвестное действие', 400);
        }

        $token = (string) ($input['token'] ?? ($_POST['token'] ?? ''));
        if ($token === '') {
            $this->error('Укажите token сессии', 400);
        }

        // Наружу полный session id не отдаётся — ищем свою сессию по sha256(id)
        $sid = null;
        foreach ($this->sessionManager->getActiveSessions($userId) as $s) {
            if (hash_equals(hash('sha256', $s['id']), $token)) {
                $sid = $s['id'];
                break;
            }
        }

        if ($sid === null) {
            $this->error('Сессия не найдена', 404);
        }

        $this->sessionManager->terminateSession($sid);

        if ($sid === session_id()) {
            $_SESSION = [];
            session_destroy();
        }

        $this->json(['success' => true]);
    }
}

$controller = new SessionController();
$controller->handle();
