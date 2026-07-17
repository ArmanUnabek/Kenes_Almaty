<?php

namespace App\Services;

class ApprovalService
{
    public static function createChain(\PDO $db, string $letterType, int $letterId, array $steps, ?int $templateId = null): int
    {
        $existing = $db->prepare(
            "SELECT id FROM approval_chains WHERE letter_type = ? AND letter_id = ? AND status IN ('pending','in_progress')"
        );
        $existing->execute([$letterType, $letterId]);
        if ($existing->fetchColumn()) {
            throw new \RuntimeException('Цепочка согласования уже существует для этого письма', 409);
        }

        $db->prepare("
            INSERT INTO approval_chains (letter_type, letter_id, template_id, current_step, status)
            VALUES (?, ?, ?, 0, 'in_progress')
        ")->execute([$letterType, $letterId, $templateId]);
        $chainId = (int)$db->lastInsertId();

        $stmt = $db->prepare("
            INSERT INTO approval_steps (chain_id, step_order, approver_role, approver_id)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($steps as $i => $step) {
            $stmt->execute([
                $chainId,
                $step['step_order'] ?? $i,
                $step['approver_role'] ?? '',
                $step['approver_id'] ?? null,
            ]);
        }

        return $chainId;
    }

    public static function getCurrentStep(string $letterType, int $letterId, \PDO $db): ?array
    {
        $chain = self::getActiveChain($db, $letterType, $letterId);
        if (!$chain) {
            return null;
        }

        $stmt = $db->prepare("
            SELECT * FROM approval_steps
            WHERE chain_id = ? AND status = 'pending'
            ORDER BY step_order ASC
            LIMIT 1
        ");
        $stmt->execute([(int)$chain['id']]);
        return $stmt->fetch() ?: null;
    }

    public static function approveStep(\PDO $db, string $letterType, int $letterId, int $userId, ?string $notes = null): array
    {
        return self::decideStep($db, $letterType, $letterId, $userId, 'approved', $notes);
    }

    public static function rejectStep(\PDO $db, string $letterType, int $letterId, int $userId, ?string $notes = null): array
    {
        return self::decideStep($db, $letterType, $letterId, $userId, 'rejected', $notes);
    }

    public static function getChainStatus(string $letterType, int $letterId, \PDO $db): ?array
    {
        $stmt = $db->prepare("
            SELECT ac.*, at.name AS template_name
            FROM approval_chains ac
            LEFT JOIN approval_templates at ON ac.template_id = at.id
            WHERE ac.letter_type = ? AND ac.letter_id = ?
            ORDER BY ac.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$letterType, $letterId]);
        $chain = $stmt->fetch();
        if (!$chain) {
            return null;
        }

        $steps = $db->prepare("
            SELECT s.*, u.full_name AS approver_name
            FROM approval_steps s
            LEFT JOIN users u ON s.approver_id = u.id
            WHERE s.chain_id = ?
            ORDER BY s.step_order ASC
        ");
        $steps->execute([(int)$chain['id']]);
        $chain['steps'] = $steps->fetchAll();

        return $chain;
    }

    private static function decideStep(\PDO $db, string $letterType, int $letterId, int $userId, string $decision, ?string $notes): array
    {
        $chain = self::getActiveChain($db, $letterType, $letterId);
        if (!$chain) {
            throw new \RuntimeException('Цепочка согласования не найдена', 404);
        }
        if ($chain['status'] === 'approved' || $chain['status'] === 'rejected') {
            throw new \RuntimeException('Согласование уже завершено', 409);
        }

        $step = self::getCurrentStep($letterType, $letterId, $db);
        if (!$step) {
            throw new \RuntimeException('Нет активного шага согласования', 422);
        }

        $userStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $userRole = (string)($userStmt->fetchColumn() ?: '');

        if ($userRole !== 'admin') {
            if ($step['approver_id']) {
                if ((int)$step['approver_id'] !== $userId) {
                    if ($userRole !== $step['approver_role']) {
                        throw new \RuntimeException('У вас нет прав для согласования этого шага', 403);
                    }
                }
            } elseif ($step['approver_role']) {
                if ($userRole !== $step['approver_role']) {
                    throw new \RuntimeException('У вас нет прав для согласования этого шага', 403);
                }
            }
        }

        $db->prepare("
            UPDATE approval_steps
            SET status = ?, approver_id = ?, decided_at = CURRENT_TIMESTAMP, notes = ?
            WHERE id = ?
        ")->execute([$decision, $userId, $notes, (int)$step['id']]);

        if ($decision === 'rejected') {
            $db->prepare("UPDATE approval_chains SET status = 'rejected', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([(int)$chain['id']]);
            self::notifyApprovalResult($db, $letterType, $letterId, 'rejected', $notes);
            return ['status' => 'rejected', 'message' => 'Согласование отклонено'];
        }

        $nextStep = $db->prepare("
            SELECT id FROM approval_steps
            WHERE chain_id = ? AND status = 'pending'
            ORDER BY step_order ASC
            LIMIT 1
        ");
        $nextStep->execute([(int)$chain['id']]);

        if ($nextStep->fetch()) {
            $db->prepare("UPDATE approval_chains SET current_step = current_step + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([(int)$chain['id']]);
            return ['status' => 'in_progress', 'message' => 'Шаг согласован, ожидается следующий'];
        }

        $db->prepare("UPDATE approval_chains SET status = 'approved', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([(int)$chain['id']]);

        self::finalizeLetter($db, $letterType, $letterId);
        self::notifyApprovalResult($db, $letterType, $letterId, 'approved', $notes);

        return ['status' => 'approved', 'message' => 'Все шаги согласованы — письмо утверждено'];
    }

    private static function getActiveChain(\PDO $db, string $letterType, int $letterId): ?array
    {
        $stmt = $db->prepare("
            SELECT * FROM approval_chains
            WHERE letter_type = ? AND letter_id = ? AND status IN ('pending','in_progress')
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$letterType, $letterId]);
        return $stmt->fetch() ?: null;
    }

    private static function finalizeLetter(\PDO $db, string $letterType, int $letterId): void
    {
        $table = $letterType === 'incoming' ? 'incoming_letters' : 'outgoing_letters';
        $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);

        try {
            $hasColumn = false;
            if ($driver === 'sqlite') {
                $stmt = $db->query("PRAGMA table_info({$table})");
                foreach ($stmt->fetchAll() as $col) {
                    if ($col['name'] === 'approval_status') { $hasColumn = true; break; }
                }
            } elseif ($driver === 'pgsql') {
                $stmt = $db->prepare(
                    "SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = 'approval_status'"
                );
                $stmt->execute([$table]);
                $hasColumn = (bool)$stmt->fetchColumn();
            } else {
                $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE 'approval_status'");
                $hasColumn = (bool)$stmt->fetch();
            }

            if ($hasColumn) {
                $db->prepare("UPDATE {$table} SET approval_status = 'approved' WHERE id = ?")
                    ->execute([$letterId]);
            }
        } catch (\Throwable $e) {
            error_log('ApprovalService::finalizeLetter failed: ' . $e->getMessage());
        }
    }

    public static function getTemplates(\PDO $db, ?int $regionId = null): array
    {
        if ($regionId) {
            $stmt = $db->prepare("SELECT * FROM approval_templates WHERE region_id = ? OR region_id IS NULL ORDER BY name");
            $stmt->execute([$regionId]);
        } else {
            $stmt = $db->query("SELECT * FROM approval_templates ORDER BY name");
        }
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['steps'] = json_decode($row['steps'] ?? '[]', true) ?: [];
        }
        return $rows;
    }

    public static function createTemplate(\PDO $db, array $data): int
    {
        $db->prepare("
            INSERT INTO approval_templates (name, region_id, steps)
            VALUES (?, ?, ?)
        ")->execute([
            $data['name'],
            $data['region_id'] ?? null,
            json_encode($data['steps'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
        return (int)$db->lastInsertId();
    }

    private static function notifyApprovalResult(\PDO $db, string $letterType, int $letterId, string $result, ?string $notes): void
    {
        try {
            $table  = $letterType === 'incoming' ? 'incoming_letters' : 'outgoing_letters';
            $numCol = $letterType === 'incoming' ? 'seq' : 'outgoing_number';
            $stmt   = $db->prepare("SELECT {$numCol} AS num, organization FROM {$table} WHERE id = ?");
            $stmt->execute([$letterId]);
            $letter = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$letter) return;

            $typeLabel = $letterType === 'incoming' ? 'Вх.' : 'Исх.';
            $resultRu  = $result === 'approved' ? 'УТВЕРЖДЕНО ✅' : 'ОТКЛОНЕНО ❌';
            $notesLine = $notes ? "\nКомментарий: {$notes}" : '';
            $msgText   = "📋 Согласование: {$typeLabel}{$letter['num']} ({$letter['organization']})\n"
                       . "Статус: {$resultRu}{$notesLine}";

            // Notify all members assigned to the letter via Telegram
            if (TelegramService::isConfigured()) {
                $stmtTg = $db->prepare("
                    SELECT DISTINCT u.telegram_chat_id
                    FROM letter_members lm
                    JOIN os_members m ON lm.member_id = m.id
                    JOIN users u ON u.member_id = m.id
                    WHERE lm.letter_type = ? AND lm.letter_id = ?
                      AND u.telegram_chat_id IS NOT NULL AND u.telegram_chat_id != ''
                ");
                $stmtTg->execute([$letterType, $letterId]);
                foreach ($stmtTg->fetchAll(\PDO::FETCH_COLUMN) as $chatId) {
                    TelegramService::sendMessage((string)$chatId, $msgText);
                }
            }

            // Notify via email
            if (defined('SMTP_HOST') && SMTP_HOST !== '') {
                $subject  = "[Журнал ОС] Согласование {$resultRu}: {$typeLabel}{$letter['num']}";
                $bodyHtml = "<p>" . nl2br(htmlspecialchars($msgText, ENT_QUOTES, 'UTF-8')) . "</p>";
                $stmtEm   = $db->prepare("
                    SELECT DISTINCT m.email
                    FROM letter_members lm
                    JOIN os_members m ON lm.member_id = m.id
                    WHERE lm.letter_type = ? AND lm.letter_id = ?
                      AND m.email IS NOT NULL AND m.email != ''
                ");
                $stmtEm->execute([$letterType, $letterId]);
                foreach ($stmtEm->fetchAll(\PDO::FETCH_COLUMN) as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        EmailService::enqueue($db, $email, $subject, $bodyHtml, strip_tags($bodyHtml));
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('ApprovalService::notifyApprovalResult failed: ' . $e->getMessage());
        }
    }

    public static function createChainFromTemplate(\PDO $db, string $letterType, int $letterId, int $templateId): int
    {
        $stmt = $db->prepare("SELECT * FROM approval_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        if (!$template) {
            throw new \RuntimeException('Шаблон согласования не найден', 404);
        }

        $steps = json_decode($template['steps'] ?? '[]', true) ?: [];
        if (empty($steps)) {
            throw new \RuntimeException('Шаблон не содержит шагов', 422);
        }

        return self::createChain($db, $letterType, $letterId, $steps, $templateId);
    }
}
