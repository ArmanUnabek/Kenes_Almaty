<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';

use App\ApiController;
use App\Services\LetterPersistenceService;
use App\Services\LetterService;

class LettersController extends ApiController
{
    private string $type;
    private string $table;

    public function handle(): void
    {
        try {
            $this->requireAuth();
            LetterPersistenceService::ensureIncomingCategorySupport($this->db);

            $this->type = $this->getQueryParam('type', 'incoming');
            $this->table = $this->type === 'incoming' ? 'incoming_letters' : 'outgoing_letters';

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

        $regionId = $this->getCurrentRegionId();
        $hasPagination = isset($_GET['limit']);
        $limit = max(1, min(500, (int)$this->getQueryParam('limit', 50)));
        $page = max(1, (int)$this->getQueryParam('page', 1));
        $offset = ($page - 1) * $limit;

        if ($regionId) {
            $sql = "SELECT * FROM {$this->table} WHERE region_id = ? ORDER BY date DESC, seq DESC";
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
            $sql = "SELECT * FROM {$this->table} ORDER BY date DESC, seq DESC";
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
        foreach ($letters as &$letter) {
            $stmt2 = $this->db->prepare('SELECT COUNT(*) as count FROM letter_scans WHERE letter_type = ? AND letter_id = ?');
            $stmt2->execute([$this->type, $letter['id']]);
            $letter['scans_count'] = (int)$stmt2->fetchColumn();
        }
        unset($letter);

        $letterIds = array_column($letters, 'id');
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

            $this->logAction($this->table, $letterId, 'CREATE', null, $data);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        pusherTrigger('council-documents', 'documents-updated', [
            'action' => 'create',
            'type' => $this->type,
            'id' => $letterId,
            'region_id' => $regionId,
        ]);
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

            if (!empty($data['delete_scan_ids']) && is_array($data['delete_scan_ids'])) {
                $ids = array_values(array_filter(array_map('intval', $data['delete_scan_ids'])));
                if ($ids) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmtDel = $this->db->prepare("DELETE FROM letter_scans WHERE letter_type = ? AND letter_id = ? AND id IN ($placeholders)");
                    $stmtDel->execute(array_merge([$this->type, $id], $ids));
                }
            }
            if (!empty($data['scans']) && is_array($data['scans'])) {
                LetterService::insertScans($this->db, $this->type, $id, $data['scans']);
            }

            $this->logAction($this->table, $id, 'UPDATE', null, $data);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        pusherTrigger('council-documents', 'documents-updated', [
            'action' => 'update',
            'type' => $this->type,
            'id' => $id,
            'region_id' => $regionId,
        ]);
        $this->json(['message' => 'Письмо успешно обновлено']);
    }

    private function handleDelete(): void
    {
        $id = (int)($this->getQueryParam('id') ?? 0);
        if ($id <= 0) {
            $this->error('ID не указан', 400);
        }

        $stmt = $this->db->prepare("SELECT region_id FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $letterRow = $stmt->fetch();
        if (!$letterRow) {
            $this->error('Письмо не найдено', 404);
        }
        LetterService::assertRegionAccess($letterRow);
        $regionId = (int)$letterRow['region_id'];

        try {
            $this->db->beginTransaction();
            if ($this->type === 'outgoing') {
                $this->db->prepare('UPDATE incoming_letters SET linked_outgoing_id = NULL WHERE linked_outgoing_id = ?')->execute([$id]);
            } else {
                $this->db->prepare('UPDATE outgoing_letters SET incoming_ref_id = NULL WHERE incoming_ref_id = ?')->execute([$id]);
            }
            $this->db->prepare('DELETE FROM letter_members WHERE letter_type = ? AND letter_id = ?')->execute([$this->type, $id]);
            $this->db->prepare('DELETE FROM letter_recipients WHERE letter_type = ? AND letter_id = ?')->execute([$this->type, $id]);
            LetterService::deleteScansForLetter($this->db, $this->type, $id);
            $this->db->prepare("DELETE FROM {$this->table} WHERE id = ?")->execute([$id]);
            $this->logAction($this->table, $id, 'DELETE', ['id' => $id], null);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        pusherTrigger('council-documents', 'documents-updated', [
            'action' => 'delete',
            'type' => $this->type,
            'id' => $id,
        ]);
        $this->json(['message' => 'Письмо успешно удалено']);
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
        $seq = $data['seq'] ?? null;
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
        $seq = $data['seq'] ?? null;
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
        if ($previousIncomingRef && $previousIncomingRef !== ($data['incoming_ref_id'] ?? null)) {
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
