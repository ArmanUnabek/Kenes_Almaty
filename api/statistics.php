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

            $thresholdDate = date('Y-m-d', strtotime('-21 days'));

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

            $stmt = $this->db->prepare('
                SELECT
                    COUNT(*) as total_incoming,
                    SUM(CASE WHEN linked_outgoing_id IS NOT NULL THEN 1 ELSE 0 END) as closed_letters,
                    SUM(CASE WHEN linked_outgoing_id IS NULL THEN 1 ELSE 0 END) as pending_letters,
                    SUM(CASE WHEN linked_outgoing_id IS NULL AND date < ? THEN 1 ELSE 0 END) as overdue_letters
                FROM incoming_letters
                WHERE region_id = ?
                  AND (deleted_at IS NULL OR deleted_at = \'0000-00-00 00:00:00\')
            ');
            $stmt->execute([$thresholdDate, $regionId]);
            $row = $stmt->fetch();
            $stats['total_incoming'] = (int)$row['total_incoming'];
            $stats['closed_letters'] = (int)$row['closed_letters'];
            $stats['pending_letters'] = (int)$row['pending_letters'];
            $stats['overdue_letters'] = (int)$row['overdue_letters'];

            $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM outgoing_letters WHERE region_id = ? AND (deleted_at IS NULL OR deleted_at = \'0000-00-00 00:00:00\')');
            $stmt->execute([$regionId]);
            $stats['total_outgoing'] = (int)$stmt->fetch()['cnt'];

            $stmt = $this->db->prepare("
                SELECT
                    COUNT(DISTINCT CASE WHEN ls.letter_type = 'incoming' THEN ls.letter_id END) as letters_with_scans,
                    COUNT(CASE WHEN ls.letter_type = 'incoming' THEN 1 END) as total_scans
                FROM letter_scans ls
                JOIN incoming_letters il ON ls.letter_id = il.id
                WHERE il.region_id = ?
                  AND (il.deleted_at IS NULL OR il.deleted_at = '0000-00-00 00:00:00')
            ");
            $stmt->execute([$regionId]);
            $row = $stmt->fetch();
            $stats['letters_with_scans'] = (int)$row['letters_with_scans'];
            $stats['total_scans'] = (int)$row['total_scans'];

            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as members_count,
                    SUM(CASE WHEN photo_path IS NOT NULL THEN 1 ELSE 0 END) as members_with_photo
                FROM os_members
                WHERE region_id = ? AND status = 'active'
            ");
            $stmt->execute([$regionId]);
            $row = $stmt->fetch();
            $stats['members_count'] = (int)$row['members_count'];
            $stats['members_with_photo'] = (int)$row['members_with_photo'];

            $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM commissions WHERE region_id = ?');
            $stmt->execute([$regionId]);
            $stats['commissions_count'] = (int)$stmt->fetch()['cnt'];

            if ($stats['total_incoming'] > 0) {
                $onTime = $stats['total_incoming'] - $stats['overdue_letters'];
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
