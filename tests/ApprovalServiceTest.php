<?php

namespace Tests;

use App\Services\ApprovalService;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests the multi-step approval flow of App\Services\ApprovalService against
 * in-memory SQLite: chain creation (one active per letter), step-by-step approval
 * to completion, rejection short-circuit, and per-step authorization.
 */
class ApprovalServiceTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, role TEXT, member_id INTEGER, telegram_chat_id TEXT)");
        $this->db->exec("INSERT INTO users (id, full_name, role) VALUES (1,'Админ','admin'),(2,'Модератор','moderator'),(3,'Гость','viewer')");
        $this->db->exec("CREATE TABLE approval_chains (
            id INTEGER PRIMARY KEY AUTOINCREMENT, letter_type TEXT, letter_id INTEGER,
            template_id INTEGER, current_step INTEGER DEFAULT 0, status TEXT DEFAULT 'in_progress',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT)");
        $this->db->exec("CREATE TABLE approval_steps (
            id INTEGER PRIMARY KEY AUTOINCREMENT, chain_id INTEGER, step_order INTEGER,
            approver_role TEXT, approver_id INTEGER, status TEXT DEFAULT 'pending',
            decided_at TEXT, notes TEXT)");
        $this->db->exec("CREATE TABLE approval_templates (id INTEGER PRIMARY KEY, name TEXT, region_id INTEGER, steps TEXT)");
        // finalize/notify touch this table; provide it so the happy path stays clean.
        $this->db->exec("CREATE TABLE incoming_letters (id INTEGER PRIMARY KEY, seq TEXT, organization TEXT, approval_status TEXT)");
        $this->db->exec("INSERT INTO incoming_letters (id, seq, organization) VALUES (5,'IN-5','Org')");
    }

    private function twoStepChain(): int
    {
        return ApprovalService::createChain($this->db, 'incoming', 5, [
            ['step_order' => 0, 'approver_role' => 'moderator'],
            ['step_order' => 1, 'approver_role' => 'admin'],
        ]);
    }

    public function testCreateChainRejectsDuplicateActiveChain(): void
    {
        $this->twoStepChain();
        $this->expectException(\RuntimeException::class);
        $this->twoStepChain();
    }

    public function testCurrentStepIsFirstPending(): void
    {
        $this->twoStepChain();
        $step = ApprovalService::getCurrentStep('incoming', 5, $this->db);
        $this->assertNotNull($step);
        $this->assertSame(0, (int)$step['step_order']);
        $this->assertSame('moderator', $step['approver_role']);
    }

    public function testWrongRoleCannotApprove(): void
    {
        $this->twoStepChain();
        $this->expectException(\RuntimeException::class);
        // viewer (id 3) is neither admin nor the step's moderator role.
        ApprovalService::approveStep($this->db, 'incoming', 5, 3);
    }

    public function testFullApprovalFlowAcrossSteps(): void
    {
        $this->twoStepChain();

        // Step 1 approved by a moderator → chain still in progress.
        $r1 = ApprovalService::approveStep($this->db, 'incoming', 5, 2, 'ok');
        $this->assertSame('in_progress', $r1['status']);

        // Current step advanced to the admin step.
        $step2 = ApprovalService::getCurrentStep('incoming', 5, $this->db);
        $this->assertSame(1, (int)$step2['step_order']);

        // Step 2 approved by admin → whole chain approved + letter finalized.
        $r2 = ApprovalService::approveStep($this->db, 'incoming', 5, 1);
        $this->assertSame('approved', $r2['status']);

        $status = ApprovalService::getChainStatus('incoming', 5, $this->db);
        $this->assertSame('approved', $status['status']);
        $this->assertCount(2, $status['steps']);
        $this->assertSame('approved', $this->db->query("SELECT approval_status FROM incoming_letters WHERE id=5")->fetchColumn());
    }

    public function testRejectionShortCircuitsChain(): void
    {
        $this->twoStepChain();
        $r = ApprovalService::rejectStep($this->db, 'incoming', 5, 1, 'нет');
        $this->assertSame('rejected', $r['status']);

        $status = ApprovalService::getChainStatus('incoming', 5, $this->db);
        $this->assertSame('rejected', $status['status']);
        // No further decisions possible.
        $this->expectException(\RuntimeException::class);
        ApprovalService::approveStep($this->db, 'incoming', 5, 1);
    }

    public function testDecideWithoutChainThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        ApprovalService::approveStep($this->db, 'incoming', 999, 1);
    }
}
