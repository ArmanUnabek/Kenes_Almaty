<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';

use App\ApiController;
use App\Services\GlobalSearchService;
use App\Services\SearchRanker;

class SearchController extends ApiController
{
    public function handle(): void
    {
        try {
            $this->requireAuth();

            $q = trim((string)$this->getQueryParam('q', ''));
            $limit = max(1, min(50, (int)$this->getQueryParam('limit', 20)));
            $scope = $this->getQueryParam('scope', 'all');

            if ($q === '') {
                $this->json(['items' => []]);
            }

            $regionId = $this->resolveRegionIdForRead();
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
            $like = '%' . $escaped . '%';
            $items = [];

            if ($scope === 'all' || $scope === 'letters' || $scope === 'archived') {
                $this->searchLetters('incoming', $q, $like, $limit, $scope, $regionId, $items);
                $this->searchLetters('outgoing', $q, $like, $limit, $scope, $regionId, $items);
            }

            if ($scope === 'all' || $scope === 'members') {
                $this->searchMembers($q, $like, $limit, $regionId, $items);
            }

            $items = SearchRanker::sort($items);

            $this->json([
                'items' => array_slice($items, 0, $limit),
                'query' => $q,
                'region_id' => $regionId,
            ]);
        } catch (\Throwable $e) {
            $this->handleException($e, 'SearchController');
        }
    }

    /**
     * Search incoming/outgoing letters with the deleted_at archived filter, falling
     * back to an unfiltered query when the deleted_at column does not exist yet.
     */
    private function searchLetters(
        string $type,
        string $q,
        string $like,
        int $limit,
        string $scope,
        ?int $regionId,
        array &$items
    ): void {
        $numberCol = $type === 'incoming' ? 'kk_number' : 'outgoing_number';
        $table = $type . '_letters';
        $regionClause = $regionId ? ' AND region_id = ?' : '';
        $isMysql = stripos($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME), 'mysql') !== false;

        $archivedFilter = $scope === 'archived'
            ? " AND (deleted_at IS NOT NULL AND deleted_at != '0000-00-00 00:00:00')"
            : " AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";

        // FULLTEXT при наличии индекса (migrations/2026_07_13_fulltext.sql), иначе LIKE.
        // Запросы короче 3 символов — сразу LIKE (ft_min_word_len).
        $useFt = $isMysql
            && mb_strlen($q) >= 3
            && GlobalSearchService::fulltextIndexExists($this->db, $table, 'ft_' . $type . '_search', ['subject', 'note', 'organization']);

        $textCondition = $useFt
            ? "MATCH(subject, note, organization) AGAINST(? IN NATURAL LANGUAGE MODE) OR {$numberCol} LIKE ?"
            : "subject LIKE ? OR note LIKE ? OR organization LIKE ? OR {$numberCol} LIKE ?";

        $select = "
            SELECT '{$type}' AS source, id, date, organization, subject, {$numberCol} AS number_label
            FROM {$table}
            WHERE ({$textCondition})
        ";
        $order = " ORDER BY date DESC LIMIT ?";

        $buildParams = function () use ($useFt, $q, $like, $regionId, $limit): array {
            $params = $useFt ? [$q, $like] : [$like, $like, $like, $like];
            if ($regionId) {
                $params[] = $regionId;
            }
            $params[] = $limit;
            return $params;
        };

        try {
            $stmt = $this->db->prepare($select . $archivedFilter . $regionClause . $order);
            $stmt->execute($buildParams());
            foreach ($stmt->fetchAll() as $row) {
                $items[] = $row;
            }
        } catch (\Throwable $e) {
            // deleted_at column not yet created. For archived scope no archived
            // letters can exist yet, so return nothing for that scope.
            if ($scope !== 'archived') {
                $stmt = $this->db->prepare($select . $regionClause . $order);
                $stmt->execute($buildParams());
                foreach ($stmt->fetchAll() as $row) {
                    $items[] = $row;
                }
            }
        }
    }

    private function searchMembers(string $q, string $like, int $limit, ?int $regionId, array &$items): void
    {
        $regionClause = $regionId ? ' AND m.region_id = ?' : '';
        $isMysql = stripos($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME), 'mysql') !== false;

        $useFt = $isMysql
            && mb_strlen($q) >= 3
            && GlobalSearchService::fulltextIndexExists($this->db, 'os_members', 'ft_members_fullname', ['full_name']);

        if ($useFt) {
            $nameCondition = "MATCH(m.full_name) AGAINST(? IN NATURAL LANGUAGE MODE)";
        } else {
            $nameCondition = "m.full_name LIKE ?";
        }

        $sql = "
            SELECT 'member' AS source, m.id, m.full_name AS subject, m.position AS organization,
                   c.name AS number_label, '' AS date
            FROM os_members m
            LEFT JOIN commissions c ON m.commission_id = c.id
            WHERE m.status = 'active'
              AND ({$nameCondition} OR m.position LIKE ? OR m.organization LIKE ?)
              {$regionClause}
            ORDER BY m.full_name ASC
            LIMIT ?
        ";
        $stmt = $this->db->prepare($sql);
        $params = [$useFt ? $q : $like, $like, $like];
        if ($regionId) {
            $params[] = $regionId;
        }
        $params[] = $limit;
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $row;
        }
    }
}

$controller = new SearchController();
$controller->handle();
