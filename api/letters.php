<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';

use App\ApiController;
use App\Services\LetterClassifier;
use App\Services\LetterPersistenceService;
use App\Services\LetterService;
use App\Services\ScanVersionService;

class LettersController extends ApiController
{
    private string $type;
    private string $table;

    public function handle(): void
    {
        try {
            $this->requireAuth();
            LetterPersistenceService::ensureIncomingCategorySupport($this->db);
            LetterClassifier::ensureTable($this->db);

            $this->type = $this->getQueryParam('type', 'incoming');
            if (!in_array($this->type, ['incoming', 'outgoing'], true)) {
                $this->error('Invalid letter type', 400);
            }
            $this->table = $this->type === 'incoming' ? 'incoming_letters' : 'outgoing_letters';

            $this->ensureSoftDeleteColumns();

            switch ($_SERVER['REQUEST_METHOD']) {
                case 'GET':
                    $this->handleGet();
                    break;
                case 'POST':
                    $this->requireWriteAccess();
                    $this->requireCsrf();
                    $action = $this->getQueryParam('action', '');
                    if ($action === 'restore') {
                        $this->requireDeleteAccess();
                        $this->handleRestore();
                    } elseif ($action === 'bulk_restore') {
                        $this->requireDeleteAccess();
                        $this->handleBulkRestore();
                    } elseif ($action === 'bulk') {
                        $this->requireDeleteAccess();
                        $this->handleBulk();
                    } else {
                        $this->handleCreate();
                    }
                    break;
                case 'PUT':
                    $this->requireWriteAccess();
                    $this->requireCsrf();
                    $this->handleUpdate();
                    break;
                case 'PATCH':
                    $this->requireWriteAccess();
                    $this->requireCsrf();
                    $this->handlePatch();
                    break;
                case 'DELETE':
                    $this->requireDeleteAccess();
                    $this->requireCsrf();
                    if ($this->getQueryParam('action') === 'bulk') {
                        $this->handleBulkDelete();
                    } else {
                        $this->handleDelete();
                    }
                    break;
                default:
                    $this->error('Метод не поддерживается', 405);
            }
        } catch (\Throwable $e) {
            $this->handleException($e, 'LettersController');
        }
    }

    private function handleGet(): void
    {
        $id = $this->getQueryParam('id');
        if ($id) {
            $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = ?");
            $stmt->execute([(int)$id]);
            $letter = $stmt->fetch();
            if (!$letter) {
                $this->error('Письмо не найдено', 404);
            }
            LetterService::assertRegionAccess($letter);
            $letter = LetterPersistenceService::attachScansToLetter($letter, $this->type, (int)$id, $this->db);
            $members = LetterPersistenceService::fetchLetterMembers($this->db, $this->type, [$letter['id']]);
            $letter['members'] = $members[$letter['id']] ?? [];
            $recipients = LetterPersistenceService::fetchLetterRecipients($this->db, $this->type, [$letter['id']]);
            $letter['recipients'] = $recipients[$letter['id']] ?? [];
            $this->json($letter);
        }

        $regionId = $this->resolveRegionIdForRead();
        $hasPagination = true;
        $limit = max(1, min(500, (int)$this->getQueryParam('limit', 50)));
        $page = max(1, (int)$this->getQueryParam('page', 1));
        $offset = ($page - 1) * $limit;
        $archived = $this->getQueryParam('archived', '0') === '1';
        $deletedFilter = $archived ? 'AND deleted_at IS NOT NULL' : 'AND (deleted_at IS NULL OR deleted_at = \'0000-00-00 00:00:00\')';

        if ($regionId) {
            $sql = "SELECT * FROM {$this->table} WHERE region_id = ? {$deletedFilter} ORDER BY date DESC, seq DESC";
            if ($hasPagination) {
                $sql .= ' LIMIT ? OFFSET ?';
            }
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(1, (int)$regionId, \PDO::PARAM_INT);
            if ($hasPagination) {
                $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
                $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
            }
            $stmt->execute();
        } else {
            $sql = "SELECT * FROM {$this->table} WHERE 1=1 {$deletedFilter} ORDER BY date DESC, seq DESC";
            if ($hasPagination) {
                $sql .= ' LIMIT ? OFFSET ?';
            }
            $stmt = $this->db->prepare($sql);
            if ($hasPagination) {
                $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
                $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
            }
            $stmt->execute();
        }

        $letters = $stmt->fetchAll();
        $letterIds = array_column($letters, 'id');

        // Счётчики сканов одним запросом вместо N+1
        $scansCount = [];
        if ($letterIds) {
            $placeholders = implode(',', array_fill(0, count($letterIds), '?'));
            $stmtScans = $this->db->prepare(
                "SELECT letter_id, COUNT(*) AS cnt FROM letter_scans
                 WHERE letter_type = ? AND letter_id IN ($placeholders)
                 GROUP BY letter_id"
            );
            $stmtScans->execute(array_merge([$this->type], $letterIds));
            foreach ($stmtScans->fetchAll() as $row) {
                $scansCount[$row['letter_id']] = (int)$row['cnt'];
            }
        }
        foreach ($letters as &$letter) {
            $letter['scans_count'] = $scansCount[$letter['id']] ?? 0;
        }
        unset($letter);

        $members = LetterPersistenceService::fetchLetterMembers($this->db, $this->type, $letterIds);
        $recipients = LetterPersistenceService::fetchLetterRecipients($this->db, $this->type, $letterIds);
        foreach ($letters as &$letter) {
            $letter['members'] = $members[$letter['id']] ?? [];
            $letter['recipients'] = $recipients[$letter['id']] ?? [];
        }
        unset($letter);

        $this->json($letters);
    }

    private function handleCreate(): void
    {
        $data = $this->getJsonInput() ?? [];
        $this->validateLetter($data);

        $regionId = resolveRegionIdForWrite(
            isset($data['region_id']) ? (int)$data['region_id'] : null
        );
        $data = $this->normalizeCrossRefs($data, $regionId);
        $createdBy = $this->currentUser['id'] ?? null;
        $members = LetterPersistenceService::normalizeMembersPayload($data['members'] ?? []);
        $recipients = LetterPersistenceService::normalizeRecipientsPayload($data['recipients'] ?? []);

        try {
            $this->db->beginTransaction();
            $letterId = $this->type === 'incoming'
                ? $this->insertIncoming($data, $regionId, $createdBy)
                : $this->insertOutgoing($data, $regionId, $createdBy);

            LetterPersistenceService::syncLetterMembers($this->db, $this->type, $letterId, $members);
            LetterPersistenceService::syncLetterRecipients($this->db, $this->type, $letterId, $recipients);

            if (!empty($data['scans'])) {
                LetterService::insertScans($this->db, $this->type, $letterId, $data['scans']);
            }

            if (empty($data['category_id']) && $this->type === 'incoming') {
                $this->autoClassify($letterId, $data, $regionId);
            }

            $this->logAction($this->table, $letterId, 'CREATE', null, $data);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->json(['id' => $letterId, 'message' => 'Письмо успешно добавлено'], 201);
    }

    private function handleUpdate(): void
    {
        $data = $this->getJsonInput() ?? [];
        $id = (int)($data['id'] ?? $this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }
        $this->validateLetter($data);

        $refColumn = $this->type === 'outgoing' ? ', incoming_ref_id' : '';
        $stmtLetter = $this->db->prepare("SELECT region_id{$refColumn} FROM {$this->table} WHERE id = ?");
        $stmtLetter->execute([$id]);
        $existingRow = $stmtLetter->fetch();
        if (!$existingRow) {
            $this->error('Письмо не найдено', 404);
        }
        LetterService::assertRegionAccess($existingRow);
        $regionId = (int)$existingRow['region_id'];
        $previousIncomingRef = $existingRow['incoming_ref_id'] ?? null;
        $data = $this->normalizeCrossRefs($data, $regionId);

        try {
            $this->db->beginTransaction();
            if ($this->type === 'incoming') {
                $this->updateIncoming($id, $data, $regionId);
            } else {
                $this->updateOutgoing($id, $data, $regionId, $previousIncomingRef);
            }

            $members = LetterPersistenceService::normalizeMembersPayload($data['members'] ?? []);
            $recipients = LetterPersistenceService::normalizeRecipientsPayload($data['recipients'] ?? []);
            LetterPersistenceService::syncLetterMembers($this->db, $this->type, $id, $members);
            LetterPersistenceService::syncLetterRecipients($this->db, $this->type, $id, $recipients);

            $currentUserId = (int)($this->currentUser['id'] ?? 0);
            if (!empty($data['delete_scan_ids']) && is_array($data['delete_scan_ids'])) {
                $ids = array_values(array_filter(array_map('intval', $data['delete_scan_ids'])));
                if ($ids) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmtDel = $this->db->prepare("DELETE FROM letter_scans WHERE letter_type = ? AND letter_id = ? AND id IN ($placeholders)");
                    $stmtDel->execute(array_merge([$this->type, $id], $ids));
                }
            }
            if (!empty($data['scans']) && is_array($data['scans'])) {
                $hasCurrent = ScanVersionService::getCurrentVersion($this->db, $this->type, $id) !== null;
                if ($hasCurrent) {
                    foreach ($data['scans'] as $scan) {
                        if (!is_array($scan)) {
                            continue;
                        }
                        ScanVersionService::uploadNewVersion($this->db, $this->type, $id, $scan, $currentUserId);
                    }
                } else {
                    LetterService::insertScans($this->db, $this->type, $id, $data['scans']);
                }
            }

            $this->logAction($this->table, $id, 'UPDATE', null, $data);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->json(['message' => 'Письмо успешно обновлено']);
    }

    private function handlePatch(): void
    {
        $data = $this->getJsonInput() ?? [];
        $id = (int)($data['id'] ?? $this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }

        $field = $data['field'] ?? '';
        $value = $data['value'] ?? null;
        if ($field === '') {
            $this->error('Поле не указано', 400);
        }

        // Те же лимиты, что и в LetterService::validateIncoming/validateOutgoing —
        // PATCH не должен позволять обходить валидацию PUT/POST.
        $textLimits = ['responsible' => 255, 'organization' => 255, 'subject' => 500, 'note' => 2000];
        if (isset($textLimits[$field])) {
            if ($value !== null && !is_scalar($value)) {
                $this->error("Поле '{$field}' должно быть строкой", 422);
            }
            $value = (string)$value;
            if (strlen($value) > $textLimits[$field]) {
                $this->error("Поле '{$field}' должно быть не более {$textLimits[$field]} символов", 422);
            }
        }

        $stmt = $this->db->prepare("SELECT region_id FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            $this->error('Письмо не найдено', 404);
        }
        LetterService::assertRegionAccess($row);

        try {
            $this->db->beginTransaction();

            if ($field === 'members' && is_array($value)) {
                $members = LetterPersistenceService::normalizeMembersPayload($value);
                LetterPersistenceService::syncLetterMembers($this->db, $this->type, $id, $members);
            } elseif ($field === 'responsible' || $field === 'organization') {
                // ЛЕГАСИ-АЛИАС: 'responsible' здесь пишет в колонку `organization`.
                // В схеме (incoming_letters / outgoing_letters) НЕТ отдельной колонки
                // responsible/assignee — «ответственные» письма моделируются связью
                // многие-ко-многим через letter_members и правятся через field='members'
                // (см. inline-edit.js openResponsibleDropdown -> patchField 'members').
                // Ни один текущий клиент не шлёт PATCH field='responsible', поэтому эта
                // ветка недостижима. Оставлена как исторический алиас. ВНИМАНИЕ: если
                // какой-либо клиент начнёт слать field='responsible' со скалярным значением,
                // это ЗАТРЁТ организацию письма. Для настоящего скалярного «ответственного»
                // потребуется отдельная колонка + маппинг (миграция здесь намеренно не заводится).
                $this->db->prepare("UPDATE {$this->table} SET organization = ? WHERE id = ?")
                    ->execute([(string)$value, $id]);
            } elseif ($field === 'subject') {
                $this->db->prepare("UPDATE {$this->table} SET subject = ? WHERE id = ?")
                    ->execute([(string)$value, $id]);
            } elseif ($field === 'note') {
                $this->db->prepare("UPDATE {$this->table} SET note = ? WHERE id = ?")
                    ->execute([(string)$value, $id]);
            } elseif ($field === 'category' && $this->type === 'incoming') {
                $allowed = ['KK', 'N', 'JT', 'ZT'];
                if (!in_array($value, $allowed, true)) {
                    $this->error('Недопустимая категория', 422);
                }
                $this->db->prepare("UPDATE {$this->table} SET category = ? WHERE id = ?")
                    ->execute([$value, $id]);
            } elseif ($field === 'scans' && is_array($value)) {
                LetterService::insertScans($this->db, $this->type, $id, $value);
            } else {
                $this->error("Поле '{$field}' не поддерживается для частичного обновления", 400);
            }

            $this->logAction($this->table, $id, 'PATCH', null, ['field' => $field, 'value' => $value]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->json(['message' => 'Поле обновлено', 'field' => $field]);
    }

    private function handleDelete(): void
    {
        $id = (int)($this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }

        $stmt = $this->db->prepare("SELECT region_id, deleted_at FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $letterRow = $stmt->fetch();
        if (!$letterRow) {
            $this->error('Письмо не найдено', 404);
        }
        LetterService::assertRegionAccess($letterRow);

        // Soft delete — move to archive
        $deletedBy = (int)($_SESSION['user_id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE {$this->table} SET deleted_at = ?, deleted_by = ? WHERE id = ?")
            ->execute([$now, $deletedBy, $id]);
        $this->logAction($this->table, $id, 'DELETE', ['id' => $id], null);

        $this->json(['message' => 'Письмо перемещено в архив']);
    }

    private function handleRestore(): void
    {
        $data = $this->getJsonInput() ?? [];
        $id   = (int)($data['id'] ?? $this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }

        $stmt = $this->db->prepare("SELECT region_id, deleted_at FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $letterRow = $stmt->fetch();
        if (!$letterRow) {
            $this->error('Письмо не найдено', 404);
        }
        LetterService::assertRegionAccess($letterRow);
        if (!$letterRow['deleted_at']) {
            $this->error('Письмо не находится в архиве', 400);
        }

        $this->db->prepare("UPDATE {$this->table} SET deleted_at = NULL, deleted_by = NULL WHERE id = ?")
            ->execute([$id]);
        $this->logAction($this->table, $id, 'UPDATE', ['deleted_at' => $letterRow['deleted_at']], ['deleted_at' => null]);

        $this->json(['message' => 'Письмо восстановлено из архива']);
    }

    private function handleBulkDelete(): void
    {
        $data = $this->getJsonInput() ?? [];
        $ids  = array_values(array_filter(array_map('intval', $data['ids'] ?? [])));
        if (empty($ids) || count($ids) > 100) {
            $this->error('ids должен содержать от 1 до 100 элементов', 400);
        }

        $deletedBy   = (int)($_SESSION['user_id'] ?? 0);
        $regionId    = $this->resolveRegionIdForRead();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $now = date('Y-m-d H:i:s');
        if ($regionId) {
            $stmt   = $this->db->prepare(
                "UPDATE {$this->table} SET deleted_at = ?, deleted_by = ? WHERE id IN ({$placeholders}) AND region_id = ? AND deleted_at IS NULL"
            );
            $params = array_merge([$now, $deletedBy], $ids, [$regionId]);
        } else {
            $params = array_merge([$now, $deletedBy], $ids);
            $stmt   = $this->db->prepare(
                "UPDATE {$this->table} SET deleted_at = ?, deleted_by = ? WHERE id IN ({$placeholders}) AND deleted_at IS NULL"
            );
        }
        $stmt->execute($params);
        $affected = $stmt->rowCount();

        $this->json(['archived' => $affected, 'message' => "Перемещено в архив: {$affected}"]);
    }

    /**
     * POST ?action=bulk — массовая операция над письмами.
     * JSON: {action: 'archive'|'delete', ids: [...]}.
     * В проекте «архив» = soft-delete (deleted_at), поэтому обе операции
     * помечают письма удалёнными; различие только в тексте ответа/аудите.
     * Региональная проверка — одним WHERE region_id = ? AND id IN (...).
     */
    private function handleBulk(): void
    {
        $data   = $this->getJsonInput() ?? [];
        $action = (string)($data['action'] ?? '');
        if (!in_array($action, ['archive', 'delete'], true)) {
            $this->error("action должен быть 'archive' или 'delete'", 400);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($data['ids'] ?? [])), static fn ($v) => $v > 0)));
        if (empty($ids) || count($ids) > 200) {
            $this->error('ids должен содержать от 1 до 200 элементов', 400);
        }

        $deletedBy    = (int)($_SESSION['user_id'] ?? 0);
        $regionId     = $this->resolveRegionIdForRead();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $regionSql    = $regionId ? ' AND region_id = ?' : '';
        $regionParam  = $regionId ? [(int)$regionId] : [];
        $now          = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();

            // Какие письма реально затронем (для аудита) — с той же региональной проверкой
            $stmtSel = $this->db->prepare(
                "SELECT id FROM {$this->table} WHERE id IN ({$placeholders}){$regionSql} AND deleted_at IS NULL"
            );
            $stmtSel->execute(array_merge($ids, $regionParam));
            $affectedIds = array_map('intval', $stmtSel->fetchAll(\PDO::FETCH_COLUMN));

            if ($affectedIds) {
                $ph2  = implode(',', array_fill(0, count($affectedIds), '?'));
                $stmt = $this->db->prepare(
                    "UPDATE {$this->table} SET deleted_at = ?, deleted_by = ? WHERE id IN ({$ph2}) AND deleted_at IS NULL"
                );
                $stmt->execute(array_merge([$now, $deletedBy], $affectedIds));

                foreach ($affectedIds as $affectedId) {
                    $this->logAction($this->table, $affectedId, 'DELETE', ['id' => $affectedId], ['bulk' => $action]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $updated = count($affectedIds);
        $this->json([
            'updated' => $updated,
            'message' => $action === 'archive'
                ? "Перемещено в архив: {$updated}"
                : "Удалено: {$updated}",
        ]);
    }

    private function handleBulkRestore(): void
    {
        $data = $this->getJsonInput() ?? [];
        $ids  = array_values(array_filter(array_map('intval', $data['ids'] ?? [])));
        if (empty($ids) || count($ids) > 100) {
            $this->error('ids должен содержать от 1 до 100 элементов', 400);
        }

        $regionId     = $this->resolveRegionIdForRead();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($regionId) {
            $params = array_merge($ids, [$regionId]);
            $stmt   = $this->db->prepare(
                "UPDATE {$this->table} SET deleted_at = NULL, deleted_by = NULL WHERE id IN ({$placeholders}) AND region_id = ? AND deleted_at IS NOT NULL"
            );
        } else {
            $params = $ids;
            $stmt   = $this->db->prepare(
                "UPDATE {$this->table} SET deleted_at = NULL, deleted_by = NULL WHERE id IN ({$placeholders}) AND deleted_at IS NOT NULL"
            );
        }
        $stmt->execute($params);
        $affected = $stmt->rowCount();

        $this->json(['restored' => $affected, 'message' => "Восстановлено: {$affected}"]);
    }

    /**
     * Нормализует и проверяет ссылки на связанные письма:
     * incoming_ref_id / responds_to_outgoing_id должны существовать и
     * принадлежать тому же региону, что и само письмо. Иначе moderator мог бы,
     * подставив чужой id, изменить linked_outgoing_id письма другого региона
     * или прочитать его номер (kk_number/outgoing_number) через автогенерацию.
     */
    private function normalizeCrossRefs(array $data, int $regionId): array
    {
        if ($this->type === 'outgoing') {
            $ref = !empty($data['incoming_ref_id']) ? (int)$data['incoming_ref_id'] : null;
            if ($ref !== null) {
                $this->assertLetterRefInRegion('incoming_letters', $ref, $regionId);
            }
            $data['incoming_ref_id'] = $ref;
        } else {
            $ref = !empty($data['responds_to_outgoing_id']) ? (int)$data['responds_to_outgoing_id'] : null;
            if ($ref !== null) {
                $this->assertLetterRefInRegion('outgoing_letters', $ref, $regionId);
            }
            $data['responds_to_outgoing_id'] = $ref;
        }
        return $data;
    }

    private function assertLetterRefInRegion(string $table, int $refId, int $regionId): void
    {
        $stmt = $this->db->prepare("SELECT region_id FROM {$table} WHERE id = ?");
        $stmt->execute([$refId]);
        $refRegion = $stmt->fetchColumn();
        if ($refRegion === false || (int)$refRegion !== $regionId) {
            $this->error('Связанное письмо не найдено в этом регионе', 422);
        }
    }

    private function autoClassify(int $letterId, array $data, int $regionId): void
    {
        try {
            $classifier = new LetterClassifier($this->db);

            $result = $classifier->classifyLetter($data['subject'] ?? null, $data['note'] ?? null);
            if ($result['category_id'] === null) {
                return;
            }

            $commissionId = $classifier->suggestCommission($result['category_id']);
            if ($commissionId) {
                $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $insertSql = 'INSERT OR IGNORE INTO letter_commissions (letter_type, letter_id, commission_id) VALUES (?, ?, ?)';
                } elseif ($driver === 'pgsql') {
                    $insertSql = 'INSERT INTO letter_commissions (letter_type, letter_id, commission_id) VALUES (?, ?, ?) ON CONFLICT DO NOTHING';
                } else {
                    $insertSql = 'INSERT IGNORE INTO letter_commissions (letter_type, letter_id, commission_id) VALUES (?, ?, ?)';
                }
                $this->db->prepare($insertSql)->execute([$this->type, $letterId, $commissionId]);
            }
        } catch (\Throwable $e) {
            error_log('autoClassify failed for letter ' . $letterId . ': ' . $e->getMessage());
        }
    }

    private function ensureSoftDeleteColumns(): void
    {
        static $checked = [];
        if (isset($checked[$this->table])) {
            return;
        }
        $checked[$this->table] = true;
        try {
            $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver !== 'mysql') {
                return;
            }
            $col = $this->db->query("SHOW COLUMNS FROM {$this->table} LIKE 'deleted_at'")->fetch();
            if (!$col) {
                $this->db->exec("ALTER TABLE {$this->table} ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL, ADD COLUMN deleted_by INT NULL DEFAULT NULL");
            }
        } catch (\Throwable $e) {
            error_log('ensureSoftDeleteColumns failed: ' . $e->getMessage());
        }
    }

    private function validateLetter(array $data): void
    {
        try {
            if ($this->type === 'incoming') {
                LetterService::validateIncoming($data);
            } else {
                LetterService::validateOutgoing($data);
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        }
    }

    private function insertIncoming(array $data, int $regionId, ?int $createdBy): int
    {
        $seq = !empty($data['seq']) ? (int)$data['seq'] : null;
        if (!$seq) {
            $seq = LetterService::computeNextSeq($this->db, $this->table, $regionId);
        }
        $respondsTo = !empty($data['responds_to_outgoing_id']) ? (int)$data['responds_to_outgoing_id'] : null;
        $kkNumber = $data['kk_number'] ?? '';
        if ($respondsTo && !$kkNumber) {
            $generated = LetterPersistenceService::buildReplyNumber($this->db, (int)$seq, $respondsTo);
            if ($generated) {
                $kkNumber = $generated;
            }
        }
        $stmt = $this->db->prepare('
            INSERT INTO incoming_letters
            (region_id, seq, date, organization, kk_number, category, subject, note, responds_to_outgoing_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $regionId, $seq,
            $data['date'] ?? date('Y-m-d'),
            $data['organization'] ?? '',
            $kkNumber,
            $data['category'] ?? 'KK',
            $data['subject'] ?? null,
            $data['note'] ?? null,
            $respondsTo,
            $createdBy,
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function insertOutgoing(array $data, int $regionId, ?int $createdBy): int
    {
        $seq = !empty($data['seq']) ? (int)$data['seq'] : null;
        if (!$seq) {
            $seq = LetterService::computeNextSeq($this->db, $this->table, $regionId);
        }
        $outgoingNumber = $this->resolveOutgoingNumber($data, (int)$seq);
        $stmt = $this->db->prepare('
            INSERT INTO outgoing_letters
            (region_id, seq, date, outgoing_number, organization, incoming_ref_id, outgoing_type, subject, note, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $regionId, $seq,
            $data['date'] ?? date('Y-m-d'),
            $outgoingNumber,
            $data['organization'] ?? '',
            $data['incoming_ref_id'] ?? null,
            LetterService::normalizeOutgoingType($data['outgoing_type'] ?? null),
            $data['subject'] ?? null,
            $data['note'] ?? null,
            $createdBy,
        ]);
        $letterId = (int)$this->db->lastInsertId();
        if (!empty($data['incoming_ref_id'])) {
            $this->db->prepare('UPDATE incoming_letters SET linked_outgoing_id = ? WHERE id = ?')
                ->execute([$letterId, $data['incoming_ref_id']]);
        }
        return $letterId;
    }

    private function updateIncoming(int $id, array $data, int $regionId): void
    {
        $seq = $this->resolveSeqForUpdate($id, $data, $regionId);
        $respondsTo = !empty($data['responds_to_outgoing_id']) ? (int)$data['responds_to_outgoing_id'] : null;
        $kkNumber = $data['kk_number'] ?? '';
        if ($respondsTo && !$kkNumber) {
            $generated = LetterPersistenceService::buildReplyNumber($this->db, (int)$seq, $respondsTo);
            if ($generated) {
                $kkNumber = $generated;
            }
        }
        $stmt = $this->db->prepare('
            UPDATE incoming_letters
            SET seq = ?, date = ?, organization = ?, kk_number = ?, category = ?, subject = ?, note = ?, responds_to_outgoing_id = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $seq,
            $data['date'] ?? date('Y-m-d'),
            $data['organization'] ?? '',
            $kkNumber,
            $data['category'] ?? 'KK',
            $data['subject'] ?? null,
            $data['note'] ?? null,
            $respondsTo,
            $id,
        ]);
    }

    private function updateOutgoing(int $id, array $data, int $regionId, $previousIncomingRef): void
    {
        $seq = $this->resolveSeqForUpdate($id, $data, $regionId);
        $outgoingNumber = $this->resolveOutgoingNumber($data, (int)$seq);
        $stmt = $this->db->prepare('
            UPDATE outgoing_letters
            SET seq = ?, date = ?, outgoing_number = ?, organization = ?, incoming_ref_id = ?, outgoing_type = ?, subject = ?, note = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $seq,
            $data['date'] ?? date('Y-m-d'),
            $outgoingNumber,
            $data['organization'] ?? '',
            $data['incoming_ref_id'] ?? null,
            LetterService::normalizeOutgoingType($data['outgoing_type'] ?? null),
            $data['subject'] ?? null,
            $data['note'] ?? null,
            $id,
        ]);
        if ($previousIncomingRef && (int)$previousIncomingRef !== (int)($data['incoming_ref_id'] ?? 0)) {
            $this->db->prepare('UPDATE incoming_letters SET linked_outgoing_id = NULL WHERE id = ? AND linked_outgoing_id = ?')
                ->execute([$previousIncomingRef, $id]);
        }
        if (!empty($data['incoming_ref_id'])) {
            $this->db->prepare('UPDATE incoming_letters SET linked_outgoing_id = ? WHERE id = ?')
                ->execute([$id, $data['incoming_ref_id']]);
        }
    }

    private function resolveOutgoingNumber(array $data, int $seq): string
    {
        $outgoingNumber = $data['outgoing_number'] ?? '';
        if (!$outgoingNumber && !empty($data['incoming_ref_id'])) {
            $stmt = $this->db->prepare('SELECT kk_number FROM incoming_letters WHERE id = ?');
            $stmt->execute([$data['incoming_ref_id']]);
            $kk = $stmt->fetchColumn();
            $outgoingNumber = $kk ? "{$seq}/{$kk}" : (string)$seq;
        }
        return $outgoingNumber ?: (string)$seq;
    }

    private function resolveSeqForUpdate(int $id, array $data, int $regionId): int
    {
        if (isset($data['seq']) && $data['seq'] !== '' && $data['seq'] !== null) {
            return (int)$data['seq'];
        }
        $stmt = $this->db->prepare("SELECT seq FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int)$existing;
        }
        return LetterService::computeNextSeq($this->db, $this->table, $regionId);
    }
}

$controller = new LettersController();
$controller->handle();
