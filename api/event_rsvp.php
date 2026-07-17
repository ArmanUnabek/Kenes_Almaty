<?php
/**
 * RSVP членов ОС на мероприятия.
 *
 * GET  ?event_id=N          — список откликов (auth, write role)
 * GET  ?my=1&event_id=N     — мой отклик на событие
 * POST {event_id, status}   — создать/обновить отклик (любая роль, нужен member_id)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';

use App\ApiController;

class EventRsvpController extends ApiController
{
    public function handle(): void
    {
        try {
            $this->requireAuth();
            header('Content-Type: application/json; charset=utf-8');

            switch ($_SERVER['REQUEST_METHOD']) {
                case 'GET':
                    $this->handleGet();
                    break;
                case 'POST':
                    $this->requireCsrf();
                    $this->handleUpsert();
                    break;
                default:
                    $this->error('Метод не поддерживается', 405);
            }
        } catch (\Throwable $e) {
            $this->handleException($e, 'EventRsvpController');
        }
    }

    /**
     * Загружает событие и проверяет региональный доступ (404/403).
     */
    private function requireEvent(int $eventId): array
    {
        $stmt = $this->db->prepare('SELECT id, region_id FROM events WHERE id = ?');
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();
        if (!$event) {
            $this->error('Мероприятие не найдено', 404);
        }
        assertEventRegionAccess($event);
        return $event;
    }

    private function handleGet(): void
    {
        $eventId = (int)($this->getQueryParam('event_id') ?? 0);
        if ($eventId <= 0) {
            $this->error('event_id обязателен', 400);
        }
        $this->requireEvent($eventId);

        $isMy = (bool)$this->getQueryParam('my');

        if ($isMy) {
            $memberId = (int)($this->currentUser['member_id'] ?? 0);
            if (!$memberId) {
                $this->json(['status' => null]);
                return;
            }
            $stmt = $this->db->prepare(
                'SELECT status, responded_at FROM event_rsvp WHERE event_id = ? AND member_id = ?'
            );
            $stmt->execute([$eventId, $memberId]);
            $row = $stmt->fetch();
            $this->json($row ?: ['status' => null]);
            return;
        }

        // Full list — requires write access
        $this->requireWriteAccess();

        $stmt = $this->db->prepare("
            SELECT r.member_id, r.status, r.responded_at, m.full_name, m.position
            FROM event_rsvp r
            JOIN os_members m ON m.id = r.member_id
            WHERE r.event_id = ?
            ORDER BY r.responded_at DESC
        ");
        $stmt->execute([$eventId]);
        $rows = $stmt->fetchAll();

        // Counts summary
        $counts = ['confirmed' => 0, 'declined' => 0, 'maybe' => 0];
        foreach ($rows as $r) {
            if (isset($counts[$r['status']])) {
                $counts[$r['status']]++;
            }
        }

        $this->json(['items' => $rows, 'counts' => $counts]);
    }

    private function handleUpsert(): void
    {
        $data = $this->getJsonInput() ?? [];
        $eventId = (int)($data['event_id'] ?? 0);
        $status  = $data['status'] ?? '';

        if ($eventId <= 0) {
            $this->error('event_id обязателен', 400);
        }
        if (!in_array($status, ['confirmed', 'declined', 'maybe'], true)) {
            $this->error('Недопустимый статус', 422);
        }

        $memberId = (int)($this->currentUser['member_id'] ?? 0);
        if (!$memberId) {
            $this->error('Аккаунт не привязан к члену ОС', 403);
        }

        // Verify event exists and belongs to an accessible region
        $this->requireEvent($eventId);

        $stmt = $this->db->prepare("
            INSERT INTO event_rsvp (event_id, member_id, status)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), responded_at = NOW()
        ");
        $stmt->execute([$eventId, $memberId, $status]);

        $this->json(['success' => true, 'status' => $status]);
    }
}

(new EventRsvpController())->handle();
