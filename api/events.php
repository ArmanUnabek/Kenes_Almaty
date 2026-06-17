<?php
require_once '../config.php';
require_once '../auth_middleware.php';

use App\Middleware\CsrfMiddleware;
use App\Services\AuditLogger;
use App\Services\FileCache;
use App\Services\LetterService;

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

header('Content-Type: application/json; charset=utf-8');

$JSON_FLAGS = JSON_ENCODE_FLAGS;

$db = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];
CsrfMiddleware::init();
checkAuth();

function currentUserIdOrNull() {
	$user = getCurrentUser();
	return $user['id'] ?? null;
}

switch ($method) {
	case 'GET':
		// ?id= -> один; иначе список (по региону пользователя, если задан)
		$id = $_GET['id'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
        $offset = ($page - 1) * $limit;
		if ($id) {
			$stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
			$stmt->execute([$id]);
			$event = $stmt->fetch();
			if (!$event) {
				http_response_code(404);
				echo json_encode(['error' => 'Мероприятие не найдено'], $JSON_FLAGS);
				exit;
			}
			$kpi = $db->prepare("SELECT * FROM event_kpi WHERE event_id = ? ORDER BY id");
			$kpi->execute([$id]);
			$event['kpi'] = $kpi->fetchAll();
			$att = $db->prepare("SELECT id, full_name, attended FROM event_attendees WHERE event_id = ? ORDER BY id");
			$att->execute([$id]);
			$event['attendees'] = $att->fetchAll();
			echo json_encode($event, $JSON_FLAGS);
		} else {
			$currentRegionId = getCurrentRegionId();
			if ($currentRegionId) {
                $count = $db->prepare("SELECT COUNT(*) FROM events e WHERE e.region_id = ?");
                $count->execute([$currentRegionId]);
                $total = (int)$count->fetchColumn();
				$stmt = $db->prepare("
					SELECT e.*,
						(SELECT COUNT(*) FROM event_attendees ea WHERE ea.event_id = e.id) AS attendees_total,
						(SELECT COALESCE(SUM(ea.attended),0) FROM event_attendees ea WHERE ea.event_id = e.id) AS attendees_present
					FROM events e
					WHERE e.region_id = ?
					ORDER BY e.event_date DESC, e.id DESC
                    LIMIT ? OFFSET ?
				");
                $stmt->bindValue(1, $currentRegionId, PDO::PARAM_INT);
                $stmt->bindValue(2, $limit, PDO::PARAM_INT);
                $stmt->bindValue(3, $offset, PDO::PARAM_INT);
				$stmt->execute();
			} else {
                $total = (int)$db->query("SELECT COUNT(*) FROM events")->fetchColumn();
				$stmt = $db->prepare("
					SELECT e.*,
						(SELECT COUNT(*) FROM event_attendees ea WHERE ea.event_id = e.id) AS attendees_total,
						(SELECT COALESCE(SUM(ea.attended),0) FROM event_attendees ea WHERE ea.event_id = e.id) AS attendees_present
					FROM events e
					ORDER BY e.event_date DESC, e.id DESC
                    LIMIT ? OFFSET ?
				");
                $stmt->bindValue(1, $limit, PDO::PARAM_INT);
                $stmt->bindValue(2, $offset, PDO::PARAM_INT);
                $stmt->execute();
			}
			echo json_encode([
                'items' => $stmt->fetchAll(),
                'pagination' => [
                    'total' => $total ?? 0,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => (int)ceil(($total ?? 0) / max(1, $limit))
                ]
            ], $JSON_FLAGS);
		}
		break;

	case 'POST':
		requireWriteAccess();
        CsrfMiddleware::requireVerification();
		$data = json_decode(file_get_contents('php://input'), true);
        try {
            LetterService::validateEvent($data ?? []);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()], $JSON_FLAGS);
            break;
        }

		$user = getCurrentUser();
		$regionId = $data['region_id'] ?? ($user['region_id'] ?? null);

		try {
			$db->beginTransaction();

			$stmt = $db->prepare("
				INSERT INTO events (region_id, title, event_date, location, participants_total, attendance_percent, notes, created_by)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?)
			");
			$stmt->execute([
				$regionId,
				$data['title'] ?? '',
				$data['event_date'] ?? date('Y-m-d'),
				$data['location'] ?? null,
				(int)($data['participants_total'] ?? 0),
				(float)($data['attendance_percent'] ?? 0),
				$data['notes'] ?? null,
				currentUserIdOrNull()
			]);
			$eventId = (int)$db->lastInsertId();

			// KPI массив [{metric, value_numeric, value_text}]
			if (!empty($data['kpi']) && is_array($data['kpi'])) {
				$ins = $db->prepare("INSERT INTO event_kpi (event_id, metric, value_numeric, value_text) VALUES (?, ?, ?, ?)");
				foreach ($data['kpi'] as $k) {
					$ins->execute([$eventId, $k['metric'] ?? '', $k['value_numeric'] ?? null, $k['value_text'] ?? null]);
				}
			}

			// Участники [{full_name, attended}]
			if (!empty($data['attendees']) && is_array($data['attendees'])) {
				$insA = $db->prepare("INSERT INTO event_attendees (event_id, full_name, attended) VALUES (?, ?, ?)");
				foreach ($data['attendees'] as $a) {
					$fullName = trim($a['full_name'] ?? '');
					if ($fullName === '') continue;
					$insA->execute([$eventId, $fullName, !empty($a['attended']) ? 1 : 0]);
				}
			}

			$db->commit();
		} catch (Throwable $e) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			http_response_code(500);
			error_log('events create failed: ' . $e->getMessage());
			echo json_encode(['error' => 'Внутренняя ошибка сервера'], $JSON_FLAGS);
			break;
		}

		pusherTrigger('council-events', 'events-updated', [
			'action' => 'create',
			'id' => $eventId,
		]);
        AuditLogger::log($db, 'events', $eventId, 'CREATE', null, $data, (int)($user['id'] ?? 0));
        (new FileCache())->forgetPrefix('kpi:');
		echo json_encode(['id' => $eventId, 'message' => 'Мероприятие добавлено'], $JSON_FLAGS);
		break;

	case 'PUT':
		requireWriteAccess();
        CsrfMiddleware::requireVerification();
		$data = json_decode(file_get_contents('php://input'), true);
		$id = $data['id'] ?? null;
		if (!$id) {
			http_response_code(400);
			echo json_encode(['error' => 'ID не указан'], $JSON_FLAGS);
			exit;
		}
        try {
            LetterService::validateEvent($data ?? []);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()], $JSON_FLAGS);
            break;
        }
		try {
			$db->beginTransaction();

			$stmt = $db->prepare("
				UPDATE events SET title = ?, event_date = ?, location = ?, participants_total = ?, attendance_percent = ?, notes = ?
				WHERE id = ?
			");
			$stmt->execute([
				$data['title'] ?? '',
				$data['event_date'] ?? date('Y-m-d'),
				$data['location'] ?? null,
				(int)($data['participants_total'] ?? 0),
				(float)($data['attendance_percent'] ?? 0),
				$data['notes'] ?? null,
				$id
			]);

			// Переписать KPI
			if (array_key_exists('kpi', $data) && is_array($data['kpi'])) {
				$del = $db->prepare("DELETE FROM event_kpi WHERE event_id = ?");
				$del->execute([$id]);
				$ins = $db->prepare("INSERT INTO event_kpi (event_id, metric, value_numeric, value_text) VALUES (?, ?, ?, ?)");
				foreach ($data['kpi'] as $k) {
					$ins->execute([$id, $k['metric'] ?? '', $k['value_numeric'] ?? null, $k['value_text'] ?? null]);
				}
			}

			// Переписать участников
			if (array_key_exists('attendees', $data) && is_array($data['attendees'])) {
				$db->prepare("DELETE FROM event_attendees WHERE event_id = ?")->execute([$id]);
				$insA = $db->prepare("INSERT INTO event_attendees (event_id, full_name, attended) VALUES (?, ?, ?)");
				foreach ($data['attendees'] as $a) {
					$fullName = trim($a['full_name'] ?? '');
					if ($fullName === '') continue;
					$insA->execute([$id, $fullName, !empty($a['attended']) ? 1 : 0]);
				}
			}

			$db->commit();
		} catch (Throwable $e) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			http_response_code(500);
			error_log('events update failed: ' . $e->getMessage());
			echo json_encode(['error' => 'Внутренняя ошибка сервера'], $JSON_FLAGS);
			break;
		}

		pusherTrigger('council-events', 'events-updated', [
			'action' => 'update',
			'id' => (int)$id,
		]);
        AuditLogger::log($db, 'events', (int)$id, 'UPDATE', null, $data, currentUserIdOrNull());
        (new FileCache())->forgetPrefix('kpi:');
		echo json_encode(['message' => 'Мероприятие обновлено'], $JSON_FLAGS);
		break;

	case 'DELETE':
		requireDeleteAccess();
        CsrfMiddleware::requireVerification();
		$id = $_GET['id'] ?? null;
		if (!$id) {
			http_response_code(400);
			echo json_encode(['error' => 'ID не указан'], $JSON_FLAGS);
			exit;
		}
		$db->prepare("DELETE FROM event_kpi WHERE event_id = ?")->execute([$id]);
		$db->prepare("DELETE FROM event_attendees WHERE event_id = ?")->execute([$id]);
		$db->prepare("DELETE FROM events WHERE id = ?")->execute([$id]); 
		pusherTrigger('council-events', 'events-updated', [
			'action' => 'delete',
			'id' => (int)$id,
		]);
        AuditLogger::log($db, 'events', (int)$id, 'DELETE', ['id' => (int)$id], null, currentUserIdOrNull());
        (new FileCache())->forgetPrefix('kpi:');
		echo json_encode(['message' => 'Мероприятие удалено'], $JSON_FLAGS);
		break;

	default:
		http_response_code(405);
		echo json_encode(['error' => 'Метод не поддерживается'], $JSON_FLAGS);
}


