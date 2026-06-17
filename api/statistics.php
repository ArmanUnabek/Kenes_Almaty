<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';

use App\ApiController;

class StatisticsController extends ApiController
{
    public function handle(): void
    {
        try {
            $this->requireAuth();
            $regionId = $this->resolveRegionIdForRead();
            if (!$regionId) {
                $this->error('Регион не выбран. Администратору нужно переключить активный регион.', 400);
            }

            $stats = [
                'total_incoming' => 0,
                'total_outgoing' => 0,
                'closed_letters' => 0,
                'avg_response_days' => 0,
                'pending_letters' => 0,
                'letters_with_scans' => 0,
                'total_scans' => 0,
                'members_count' => 0,
                'commissions_count' => 0,
                'members_with_photo' => 0,
                'overdue_letters' => 0,
                'on_time_percentage' => 0,
                'scans_percentage' => 0,
            ];

            $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM incoming_letters WHERE region_id = ?');
            $stmt->execute([$regionId]);
            $stats['total_incoming'] = (int)$stmt->fetch()['cnt'];

            $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM outgoing_letters WHERE region_id = ?');
            $stmt->execute([$regionId]);
            $stats['total_outgoing'] = (int)$stmt->fetch()['cnt'];

            $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM incoming_letters WHERE region_id = ? AND linked_outgoing_id IS NOT NULL');
            $stmt->execute([$regionId]);
            $stats['closed_letters'] = (int)$stmt->fetch()['cnt'];

            $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM incoming_letters WHERE region_id = ? AND linked_outgoing_id IS NULL');
            $stmt->execute([$regionId]);
            $stats['pending_letters'] = (int)$stmt->fetch()['cnt'];

            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT ls.letter_id) as cnt
                FROM letter_scans ls
                JOIN incoming_letters il ON ls.letter_type = 'incoming' AND ls.letter_id = il.id
                WHERE il.region_id = ?
            ");
            $stmt->execute([$regionId]);
            $stats['letters_with_scans'] = (int)$stmt->fetch()['cnt'];

            $stmt = $this->db->prepare("
                SELECT COUNT(*) as cnt
                FROM letter_scans ls
                JOIN incoming_letters il ON ls.letter_type = 'incoming' AND ls.letter_id = il.id
                WHERE il.region_id = ?
            ");
            $stmt->execute([$regionId]);
            $stats['total_scans'] = (int)$stmt->fetch()['cnt'];

            $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM os_members WHERE region_id = ? AND status = 'active'");
            $stmt->execute([$regionId]);
            $stats['members_count'] = (int)$stmt->fetch()['cnt'];

            $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM commissions WHERE region_id = ?');
            $stmt->execute([$regionId]);
            $stats['commissions_count'] = (int)$stmt->fetch()['cnt'];

            $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM os_members WHERE region_id = ? AND photo_path IS NOT NULL AND status = 'active'");
            $stmt->execute([$regionId]);
            $stats['members_with_photo'] = (int)$stmt->fetch()['cnt'];

            $thresholdDate = date('Y-m-d', strtotime('-21 days'));
            $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM incoming_letters WHERE region_id = ? AND linked_outgoing_id IS NULL AND date < ?');
            $stmt->execute([$regionId, $thresholdDate]);
            $stats['overdue_letters'] = (int)$stmt->fetch()['cnt'];

            if ($stats['total_incoming'] > 0) {
                $onTime = max(0, $stats['closed_letters'] - $stats['overdue_letters']);
                $stats['on_time_percentage'] = round(($onTime / $stats['total_incoming']) * 100);
                $stats['scans_percentage'] = round(($stats['letters_with_scans'] / $stats['total_incoming']) * 100);
            }

            if (DB_DRIVER === 'sqlite') {
                $avgQuery = 'SELECT AVG(julianday(ol.date) - julianday(il.date)) as avg_days FROM incoming_letters il LEFT JOIN outgoing_letters ol ON il.linked_outgoing_id = ol.id WHERE il.region_id = ? AND ol.date IS NOT NULL';
            } elseif (DB_DRIVER === 'pgsql') {
                $avgQuery = 'SELECT AVG(ol.date - il.date) as avg_days FROM incoming_letters il LEFT JOIN outgoing_letters ol ON il.linked_outgoing_id = ol.id WHERE il.region_id = ? AND ol.date IS NOT NULL';
            } else {
                $avgQuery = 'SELECT AVG(DATEDIFF(ol.date, il.date)) as avg_days FROM incoming_letters il LEFT JOIN outgoing_letters ol ON il.linked_outgoing_id = ol.id WHERE il.region_id = ? AND ol.date IS NOT NULL';
            }
            $stmt = $this->db->prepare($avgQuery);
            $stmt->execute([$regionId]);
            $result = $stmt->fetch();
            $stats['avg_response_days'] = $result['avg_days'] ? round($result['avg_days']) : 0;

            $this->json($stats);
        } catch (\Throwable $e) {
            $this->handleException($e, 'StatisticsController');
        }
    }
}

$controller = new StatisticsController();
$controller->handle();
