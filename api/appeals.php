<?php
/**
 * Обращения граждан.
 *
 * POST (публичный, без авторизации):
 *   Отправить обращение. Rate limit 3/IP/24h, honeypot, CSRF.
 *
 * GET (авторизация обязательна):
 *   Список обращений с пагинацией и фильтрами.
 *   ?id=N → одно обращение.
 *
 * PUT (авторизация, moderator+):
 *   Изменить статус, назначить исполнителя, добавить ответ.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';
require_once __DIR__ . '/../src/Middleware/RateLimiter.php';

use App\ApiController;
use App\Middleware\RateLimiter;

class AppealsController extends ApiController
{
    public function handle(): void
    {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $method = $_SERVER['REQUEST_METHOD'];

            if ($method === 'POST') {
                $this->handleCreate();
                return;
            }

            // All other methods require auth
            $this->requireAuth();

            switch ($method) {
                case 'GET':
                    $this->handleGet();
                    break;
                case 'PUT':
                    $this->requireCsrf();
                    $this->requireWriteAccess();
                    $this->handleUpdate();
                    break;
                default:
                    $this->error('Метод не поддерживается', 405);
            }
        } catch (\Throwable $e) {
            $this->handleException($e, 'AppealsController');
        }
    }

    private function handleCreate(): void
    {
        // CSRF
        $this->requireCsrf();

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Fail-fast if rate limit already exceeded (does not consume a slot)
        if (!RateLimiter::check('appeal_' . $ip, 3, 86400)) {
            $this->json(['error' => 'Слишком много обращений. Попробуйте через 24 часа.'], 429);
        }

        $data = $this->getJsonInput() ?? [];

        // Honeypot: field "url" must be empty
        if (!empty($data['url'])) {
            $this->json(['success' => true, 'appeal_number' => null]);
            return;
        }

        // Validate required fields
        $errors = [];
        $fullName = trim($data['full_name'] ?? '');
        $email    = trim($data['email'] ?? '');
        $phone    = trim($data['phone'] ?? '');
        $subject  = trim($data['subject'] ?? '');
        $message  = trim($data['message'] ?? '');
        $regionId = (int)($data['region_id'] ?? 0) ?: null;
        $category = $data['category'] ?? 'other';

        if ($fullName === '') $errors['full_name'] = 'ФИО обязательно';
        if ($email === '' && $phone === '') $errors['contact'] = 'Укажите email или телефон';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Неверный формат email';
        if ($subject === '') $errors['subject'] = 'Тема обязательна';
        if (mb_strlen($message) < 10) $errors['message'] = 'Текст обращения слишком короткий (мин. 10 символов)';

        $allowedCategories = ['complaint', 'suggestion', 'question', 'request', 'other'];
        if (!in_array($category, $allowedCategories, true)) $category = 'other';

        if ($errors) {
            $this->json(['error' => 'Ошибка валидации', 'errors' => $errors], 422);
        }

        // Consume a rate-limit slot only after successful validation
        RateLimiter::requireCheck('appeal_' . $ip, 3, 86400);

        // Verify region exists (if given)
        if ($regionId) {
            $chk = $this->db->prepare('SELECT id FROM regions WHERE id = ?');
            $chk->execute([$regionId]);
            if (!$chk->fetchColumn()) $regionId = null;
        }

        // Insert
        $stmt = $this->db->prepare("
            INSERT INTO citizen_appeals
              (region_id, full_name, email, phone, subject, message, category, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $regionId,
            $fullName,
            $email ?: null,
            $phone ?: null,
            $subject,
            $message,
            $category,
            $ip,
        ]);
        $appealId = (int)$this->db->lastInsertId();

        // Generate appeal_number
        $year   = date('Y');
        $number = sprintf('ОС-%d-%04d', $year, $appealId);
        $this->db->prepare('UPDATE citizen_appeals SET appeal_number = ? WHERE id = ?')
                 ->execute([$number, $appealId]);

        // Notify admins
        $this->notifyAdmins($appealId, $number, $fullName, $subject);

        $this->json(['success' => true, 'appeal_number' => $number]);
    }

    private function handleGet(): void
    {
        $user    = $this->currentUser;
        $isAdmin = \App\Auth\AccessPolicy::isAdmin($user['role'] ?? '');

        // Экспорт списка (CSV/JSON) — те же права, что и остальной экспорт (admin)
        $exportFormat = strtolower((string)($this->getQueryParam('export') ?? ''));
        if ($exportFormat !== '') {
            $this->handleExport($exportFormat, $isAdmin);
            return;
        }

        $id = (int)($this->getQueryParam('id') ?? 0);

        if ($id > 0) {
            $stmt = $this->db->prepare("
                SELECT a.*, r.name_ru AS region_name,
                       m.full_name AS assigned_name
                FROM citizen_appeals a
                LEFT JOIN regions r ON r.id = a.region_id
                LEFT JOIN os_members m ON m.id = a.assigned_member_id
                WHERE a.id = ?
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) $this->error('Обращение не найдено', 404);
            // Region access check for non-admins
            if (!$isAdmin && $row['region_id'] && !canAccessRegion((int)$row['region_id'])) {
                $this->error('Нет доступа к этому обращению', 403);
            }
            $this->json($row);
            return;
        }

        // Paginated list with filters
        $page   = max(1, (int)($this->getQueryParam('page') ?? 1));
        $limit  = min(100, max(1, (int)($this->getQueryParam('limit') ?? 30)));
        $offset = ($page - 1) * $limit;

        $where  = ['1=1'];
        $params = [];

        // Restrict non-admins to their own region
        if (!$isAdmin) {
            $userRegionId = resolveRegionIdForRead();
            if ($userRegionId) {
                $where[] = 'a.region_id = ?';
                $params[] = $userRegionId;
            }
        }

        $status = $this->getQueryParam('status');
        if ($status && in_array($status, ['new', 'in_review', 'responded', 'closed'], true)) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }

        $regionId = (int)($this->getQueryParam('region_id') ?? 0);
        if ($regionId > 0 && ($isAdmin || canAccessRegion($regionId))) {
            $where[] = 'a.region_id = ?';
            $params[] = $regionId;
        }

        $category = $this->getQueryParam('category');
        if ($category && in_array($category, ['complaint', 'suggestion', 'question', 'request', 'other'], true)) {
            $where[] = 'a.category = ?';
            $params[] = $category;
        }

        $search = trim($this->getQueryParam('search') ?? '');
        if ($search !== '') {
            $where[] = '(a.full_name LIKE ? OR a.subject LIKE ? OR a.appeal_number LIKE ?)';
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereStr = implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM citizen_appeals a WHERE {$whereStr}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $listStmt = $this->db->prepare("
            SELECT a.id, a.appeal_number, a.full_name, a.email, a.phone,
                   a.subject, a.category, a.status, a.created_at,
                   r.name_ru AS region_name
            FROM citizen_appeals a
            LEFT JOIN regions r ON r.id = a.region_id
            WHERE {$whereStr}
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $listParams = array_merge($params, [$limit, $offset]);
        $listStmt->execute($listParams);
        $items = $listStmt->fetchAll();

        $this->paginated($items, $total, $page, $limit);
    }

    /**
     * Экспорт обращений в CSV/JSON (для Экспорт-центра).
     * Права — как у остального экспорта: только admin (AccessPolicy::canExport).
     * Фильтры: date_from/date_to (created_at), region_id (admin: >0 или 'all').
     */
    private function handleExport(string $format, bool $isAdmin): void
    {
        if (!\App\Auth\AccessPolicy::canExport($this->currentUser['role'] ?? '')) {
            $this->error('Экспорт доступен только администратору', 403);
        }
        if (!in_array($format, ['csv', 'json'], true)) {
            $this->error('Формат должен быть csv или json', 400);
        }

        $where  = ['1=1'];
        $params = [];

        // Регион: admin может указать любой ('all' = все), по умолчанию активный
        $requestedRegion = (string)($this->getQueryParam('region_id') ?? '');
        if ($requestedRegion !== '' && $requestedRegion !== 'all' && (int)$requestedRegion > 0) {
            $where[] = 'a.region_id = ?';
            $params[] = (int)$requestedRegion;
        } elseif ($requestedRegion !== 'all') {
            $regionId = resolveRegionIdForRead();
            if ($regionId) {
                $where[] = 'a.region_id = ?';
                $params[] = $regionId;
            }
        }

        $validDate = static function ($raw): ?string {
            $raw = (string)($raw ?? '');
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
                return null;
            }
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $raw : null;
        };
        $dateFrom = $validDate($this->getQueryParam('date_from'));
        $dateTo   = $validDate($this->getQueryParam('date_to'));
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        if ($dateFrom !== null) {
            $where[] = 'a.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $where[] = 'a.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $stmt = $this->db->prepare('
            SELECT a.appeal_number, a.created_at, a.full_name, a.email, a.phone,
                   a.subject, a.category, a.status, a.responded_at,
                   r.name_ru AS region_name
            FROM citizen_appeals a
            LEFT JOIN regions r ON r.id = a.region_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY a.created_at DESC
            LIMIT 10000
        ');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $filename = 'appeals_' . date('Y-m-d');

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.json"');
            echo json_encode([
                'exported_at' => date('c'),
                'items' => $rows,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Номер', 'Дата', 'ФИО', 'Email', 'Телефон', 'Тема', 'Категория', 'Статус', 'Ответ дан', 'Регион'], ';');
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['appeal_number'] ?? '',
                $row['created_at'] ?? '',
                \App\Services\SpreadsheetExporter::sanitizeCell($row['full_name'] ?? ''),
                \App\Services\SpreadsheetExporter::sanitizeCell($row['email'] ?? ''),
                \App\Services\SpreadsheetExporter::sanitizeCell($row['phone'] ?? ''),
                \App\Services\SpreadsheetExporter::sanitizeCell($row['subject'] ?? ''),
                $row['category'] ?? '',
                $row['status'] ?? '',
                $row['responded_at'] ?? '',
                \App\Services\SpreadsheetExporter::sanitizeCell($row['region_name'] ?? ''),
            ], ';');
        }
        fclose($out);
        exit;
    }

    private function handleUpdate(): void
    {
        $data = $this->getJsonInput() ?? [];
        $id   = (int)($data['id'] ?? 0);
        if ($id <= 0) $this->error('id обязателен', 400);

        // Verify exists
        $check = $this->db->prepare('SELECT id, status, region_id FROM citizen_appeals WHERE id = ?');
        $check->execute([$id]);
        $existing = $check->fetch();
        if (!$existing) $this->error('Обращение не найдено', 404);

        // Region access check for non-admins
        $isAdmin = \App\Auth\AccessPolicy::isAdmin($this->currentUser['role'] ?? '');
        if (!$isAdmin && $existing['region_id'] && !canAccessRegion((int)$existing['region_id'])) {
            $this->error('Нет доступа к этому обращению', 403);
        }

        $allowed = ['new', 'in_review', 'responded', 'closed'];
        $status  = isset($data['status']) && in_array($data['status'], $allowed, true)
            ? $data['status'] : null;

        $assignedMemberId = array_key_exists('assigned_member_id', $data)
            ? ((int)$data['assigned_member_id'] ?: null)
            : 'skip';

        $responseText = array_key_exists('response_text', $data)
            ? (trim($data['response_text'] ?? '') ?: null)
            : 'skip';

        $sets   = [];
        $params = [];

        if ($status !== null) {
            $sets[] = 'status = ?';
            $params[] = $status;
            if ($status === 'responded') {
                $sets[] = 'responded_at = NOW()';
                $sets[] = 'responded_by = ?';
                $params[] = (int)($this->currentUser['id'] ?? 0);
            }
        }
        if ($assignedMemberId !== 'skip') {
            $sets[] = 'assigned_member_id = ?';
            $params[] = $assignedMemberId;
        }
        if ($responseText !== 'skip') {
            $sets[] = 'response_text = ?';
            $params[] = $responseText;
        }

        if (empty($sets)) $this->error('Нет полей для обновления', 400);

        $params[] = $id;
        $this->db->prepare('UPDATE citizen_appeals SET ' . implode(', ', $sets) . ' WHERE id = ?')
                 ->execute($params);

        $this->json(['success' => true]);
    }

    private function notifyAdmins(int $appealId, string $number, string $fullName, string $subject): void
    {
        // Telegram
        try {
            $adminRows = $this->db->query(
                "SELECT telegram_chat_id FROM users WHERE telegram_chat_id IS NOT NULL AND is_active = TRUE AND role IN ('admin','moderator')"
            );
            $text = "📨 <b>Новое обращение гражданина</b>\n\n" .
                    "Номер: <b>{$number}</b>\n" .
                    "От: " . htmlspecialchars(mb_substr($fullName, 0, 60), ENT_QUOTES) . "\n" .
                    "Тема: " . htmlspecialchars(mb_substr($subject, 0, 80), ENT_QUOTES);
            foreach ($adminRows as $row) {
                \App\Services\TelegramService::sendMessage((string)$row['telegram_chat_id'], $text);
            }
        } catch (\Throwable $e) {
            error_log('Appeals: Telegram notify failed: ' . $e->getMessage());
        }

        // Email to admin
        try {
            $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '';
            if ($adminEmail) {
                \App\Services\EmailService::enqueue(
                    $this->db,
                    $adminEmail,
                    "Новое обращение {$number}",
                    "<p><b>Обращение {$number}</b></p><p>От: " . htmlspecialchars($fullName, ENT_QUOTES) . "</p><p>Тема: " . htmlspecialchars($subject, ENT_QUOTES) . "</p>",
                );
            }
        } catch (\Throwable $e) {
            error_log('Appeals: Email notify failed: ' . $e->getMessage());
        }
    }
}

(new AppealsController())->handle();
