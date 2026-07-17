<?php

namespace App\Services;

use PDO;

/**
 * Unified global search across letters, members, events, and comments.
 * Uses FULLTEXT when available, falls back to LIKE. Results are ranked
 * by SearchRanker with a relevance_score computed per item.
 */
final class GlobalSearchService
{
    /**
     * Запросы короче ft_min_word_len (по умолчанию 4 в MySQL, 3 в InnoDB/MariaDB)
     * FULLTEXT не найдёт — такие сразу идут по LIKE-пути.
     */
    private const FT_MIN_QUERY_LEN = 3;

    /** @var array<string, bool> per-request cache наличия FULLTEXT-индексов */
    private static array $ftIndexCache = [];

    private PDO $db;
    private bool $isMysql;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->isMysql = stripos($db->getAttribute(PDO::ATTR_DRIVER_NAME), 'mysql') !== false;
    }

    /**
     * Есть ли FULLTEXT-индекс, покрывающий РОВНО ожидаемый набор колонок
     * (см. migrations/2026_07_13_fulltext.sql).
     *
     * Проверяется не только имя индекса, но и состав колонок: MySQL требует, чтобы
     * список колонок в MATCH(...) точно совпадал с составом FULLTEXT-индекса, иначе
     * ERROR 1191. Одноимённый индекс с ДРУГИМ набором колонок (например базовый
     * ft_incoming_search из deploy_database.sql на 4 колонки) → метод вернёт false,
     * и вызывающий код уйдёт в безопасный LIKE-фолбэк вместо падающего MATCH.
     *
     * Порядок колонок для MATCH не важен — сравниваем как множество (регистронезав.).
     * information_schema.STATISTICS отдаёт по строке на колонку индекса.
     *
     * Драйвер mysql; положительный результат кэшируется в FileCache на 24ч
     * (как DDL-кэш в api/comments.php) и в статике на время запроса; ключ кэша
     * включает состав колонок. На sqlite всегда false — LIKE-фолбэк.
     *
     * @param array<int, string> $expectedColumns колонки, перечисленные в MATCH()
     */
    public static function fulltextIndexExists(PDO $db, string $table, string $indexName, array $expectedColumns): bool
    {
        if (stripos((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME), 'mysql') === false) {
            return false;
        }

        $expectedSet = self::normalizeColumns($expectedColumns);
        $key = 'schema_ft_' . $table . '_' . $indexName . '_' . implode(',', $expectedSet);
        if (array_key_exists($key, self::$ftIndexCache)) {
            return self::$ftIndexCache[$key];
        }

        $cache = class_exists(FileCache::class) ? new FileCache() : null;
        if ($cache !== null && $cache->get($key) === true) {
            return self::$ftIndexCache[$key] = true;
        }

        $matches = false;
        try {
            $stmt = $db->prepare(
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                   AND index_name = ?
                   AND index_type = 'FULLTEXT'
                 ORDER BY SEQ_IN_INDEX"
            );
            $stmt->execute([$table, $indexName]);
            $actualColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($actualColumns) {
                $matches = self::normalizeColumns($actualColumns) === $expectedSet;
            }
        } catch (\Throwable $e) {
            // Старый MySQL без FULLTEXT на InnoDB / нет прав на information_schema — LIKE-фолбэк
            error_log('GlobalSearchService::fulltextIndexExists(' . $table . '): ' . $e->getMessage());
        }

        if ($matches && $cache !== null) {
            $cache->set($key, true, 86400);
        }

        return self::$ftIndexCache[$key] = $matches;
    }

    /**
     * Нормализует список колонок в множество для сравнения независимо от порядка
     * и регистра: lower-case, trim, дедуп, сортировка.
     *
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    private static function normalizeColumns(array $columns): array
    {
        $normalized = array_map(
            static fn ($c): string => strtolower(trim((string)$c)),
            $columns
        );
        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    /**
     * @param array<int, string> $columns колонки, перечисленные в MATCH()
     */
    private function canUseFulltext(string $q, string $table, string $indexName, array $columns): bool
    {
        if (!$this->isMysql || mb_strlen($q) < self::FT_MIN_QUERY_LEN) {
            return false;
        }
        return self::fulltextIndexExists($this->db, $table, $indexName, $columns);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, ?int $userId = null, ?int $regionId = null, int $limit = 20): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $like = '%' . $escaped . '%';
        $items = [];

        $this->searchLetters('incoming', $q, $like, $limit, $regionId, $items);
        $this->searchLetters('outgoing', $q, $like, $limit, $regionId, $items);
        $this->searchMembers($q, $like, $limit, $regionId, $items);
        $this->searchEvents($q, $like, $limit, $regionId, $items);
        $this->searchComments($q, $like, $limit, $regionId, $items);

        foreach ($items as &$item) {
            $item['relevance_score'] = self::computeRelevance($item, $q);
        }
        unset($item);

        usort($items, function (array $a, array $b) {
            $cmp = ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
        });

        return array_slice($items, 0, $limit);
    }

    private function searchLetters(
        string $type,
        string $q,
        string $like,
        int $limit,
        ?int $regionId,
        array &$items
    ): void {
        $numberCol = $type === 'incoming' ? 'kk_number' : 'outgoing_number';
        $table = $type . '_letters';
        $regionClause = $regionId ? ' AND region_id = ?' : '';
        $archivedFilter = " AND deleted_at IS NULL";

        // Список колонок в MATCH() обязан совпадать с составным FULLTEXT-индексом
        // ft_{$type}_search (subject, note, organization) из migrations/2026_07_13_fulltext.sql.
        $useFt = $this->canUseFulltext($q, $table, 'ft_' . $type . '_search', ['subject', 'note', 'organization']);
        if ($useFt) {
            $match = "MATCH(subject, note, organization) AGAINST(? IN NATURAL LANGUAGE MODE)";
            $ftScoreExpr = $match;
            $textCondition = "{$match} OR {$numberCol} LIKE ?";
        } else {
            $ftScoreExpr = '0';
            $textCondition = "subject LIKE ? OR note LIKE ? OR organization LIKE ? OR {$numberCol} LIKE ?";
        }

        $select = "
            SELECT '{$type}' AS type, id, date, organization, subject, {$numberCol} AS number_label,
                   note, '' AS snippet_source, {$ftScoreExpr} AS ft_score
            FROM {$table}
            WHERE ({$textCondition})
        ";
        $order = " ORDER BY date DESC LIMIT ?";

        $buildParams = function () use ($useFt, $like, $q, $regionId, $limit): array {
            // порядок плейсхолдеров: сначала ft_score в SELECT, затем WHERE
            $params = $useFt ? [$q, $q, $like] : [$like, $like, $like, $like];
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
                $row['snippet_source'] = $row['subject'] ?? '';
                $row['title'] = $row['subject'] ?? '';
                $row['url'] = $type === 'incoming' ? "#/letters/incoming/{$row['id']}" : "#/letters/outgoing/{$row['id']}";
                $items[] = $row;
            }
        } catch (\Throwable $e) {
            try {
                $stmt = $this->db->prepare($select . $regionClause . $order);
                $stmt->execute($buildParams());
                foreach ($stmt->fetchAll() as $row) {
                    $row['snippet_source'] = $row['subject'] ?? '';
                    $row['title'] = $row['subject'] ?? '';
                    $row['url'] = $type === 'incoming' ? "#/letters/incoming/{$row['id']}" : "#/letters/outgoing/{$row['id']}";
                    $items[] = $row;
                }
            } catch (\Throwable $e2) {
                error_log('GlobalSearchService::searchLetters(' . $type . '): ' . $e2->getMessage());
            }
        }
    }

    private function searchMembers(string $q, string $like, int $limit, ?int $regionId, array &$items): void
    {
        $regionClause = $regionId ? ' AND m.region_id = ?' : '';

        if ($this->canUseFulltext($q, 'os_members', 'ft_members_fullname', ['full_name'])) {
            $nameCondition = "MATCH(m.full_name) AGAINST(? IN NATURAL LANGUAGE MODE)";
            $nameParam = $q;
        } else {
            $nameCondition = "m.full_name LIKE ?";
            $nameParam = $like;
        }

        $sql = "
            SELECT 'member' AS type, m.id, m.full_name AS title, m.position, m.organization,
                   m.phone, c.name AS commission_name, '' AS snippet_source
            FROM os_members m
            LEFT JOIN commissions c ON m.commission_id = c.id
            WHERE m.status = 'active'
              AND ({$nameCondition} OR m.position LIKE ? OR m.organization LIKE ?)
              {$regionClause}
            ORDER BY m.full_name ASC
            LIMIT ?
        ";
        $params = [$nameParam, $like, $like];
        if ($regionId) {
            $params[] = $regionId;
        }
        $params[] = $limit;

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $row['snippet_source'] = trim(($row['position'] ?? '') . ' ' . ($row['organization'] ?? ''));
                $row['url'] = "#/members/{$row['id']}";
                $items[] = $row;
            }
        } catch (\Throwable $e) {
            error_log('GlobalSearchService::searchMembers: ' . $e->getMessage());
        }
    }

    private function searchEvents(string $q, string $like, int $limit, ?int $regionId, array &$items): void
    {
        $regionClause = $regionId ? ' AND region_id = ?' : '';

        if ($this->canUseFulltext($q, 'events', 'ft_events_search', ['title', 'location', 'notes'])) {
            $textCondition = "MATCH(title, location, notes) AGAINST(? IN NATURAL LANGUAGE MODE)";
            $params = [$q];
        } else {
            $textCondition = "title LIKE ? OR location LIKE ? OR notes LIKE ?";
            $params = [$like, $like, $like];
        }

        $sql = "
            SELECT 'event' AS type, id, title, event_date AS date, location, notes,
                   participants_total, attendance_percent, '' AS snippet_source
            FROM events
            WHERE ({$textCondition})
              {$regionClause}
            ORDER BY event_date DESC
            LIMIT ?
        ";
        if ($regionId) {
            $params[] = $regionId;
        }
        $params[] = $limit;

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $row['snippet_source'] = $row['notes'] ?? $row['location'] ?? '';
                $row['url'] = "#/events/{$row['id']}";
                $items[] = $row;
            }
        } catch (\Throwable $e) {
            error_log('GlobalSearchService::searchEvents: ' . $e->getMessage());
        }
    }

    private function searchComments(string $q, string $like, int $limit, ?int $regionId, array &$items): void
    {
        $regionFilter = '';

        if ($regionId) {
            $regionFilter = " AND ((
                c.letter_type = 'incoming' AND EXISTS (SELECT 1 FROM incoming_letters il WHERE il.id = c.letter_id AND il.region_id = ?)
              ) OR (
                c.letter_type = 'outgoing' AND EXISTS (SELECT 1 FROM outgoing_letters ol WHERE ol.id = c.letter_id AND ol.region_id = ?)
              ))";
        }

        if ($this->canUseFulltext($q, 'letter_comments', 'ft_comments_comment', ['comment'])) {
            $textCondition = "MATCH(c.comment) AGAINST(? IN NATURAL LANGUAGE MODE)";
            $params = [$q];
        } else {
            $textCondition = "c.comment LIKE ?";
            $params = [$like];
        }

        $sql = "
            SELECT 'comment' AS type, c.id, c.letter_type, c.letter_id, c.comment,
                   c.user_id, c.created_at AS date, u.full_name AS user_name,
                   '' AS snippet_source
            FROM letter_comments c
            LEFT JOIN users u ON c.user_id = u.id
            WHERE {$textCondition}
              {$regionFilter}
            ORDER BY c.created_at DESC
            LIMIT ?
        ";
        if ($regionId) {
            $params[] = $regionId;
            $params[] = $regionId;
        }
        $params[] = $limit;

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $row['title'] = mb_substr($row['comment'] ?? '', 0, 80);
                $row['snippet_source'] = $row['comment'] ?? '';
                $lt = $row['letter_type'] ?? 'incoming';
                $lid = $row['letter_id'] ?? 0;
                $row['url'] = "#/letters/{$lt}/{$lid}";
                $items[] = $row;
            }
        } catch (\Throwable $e) {
            error_log('GlobalSearchService::searchComments: ' . $e->getMessage());
        }
    }

    /**
     * Build a snippet with surrounding context and highlighted match.
     */
    public static function buildSnippet(string $text, string $query, int $contextLen = 80): string
    {
        if ($text === '' || $query === '') {
            return mb_substr($text, 0, $contextLen * 2);
        }

        $pos = mb_stripos($text, $query);
        if ($pos === false) {
            return mb_substr($text, 0, $contextLen * 2) . (mb_strlen($text) > $contextLen * 2 ? '...' : '');
        }

        $start = max(0, $pos - $contextLen);
        $end = min(mb_strlen($text), $pos + mb_strlen($query) + $contextLen);
        $snippet = '';

        if ($start > 0) {
            $snippet .= '...';
        }
        $snippet .= mb_substr($text, $start, $end - $start);
        if ($end < mb_strlen($text)) {
            $snippet .= '...';
        }

        return $snippet;
    }

    /**
     * Highlight query matches in a snippet using <mark> tags.
     */
    public static function highlightMatch(string $text, string $query): string
    {
        if ($query === '' || $text === '') {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }

        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $queryEscaped = preg_quote(htmlspecialchars($query, ENT_QUOTES, 'UTF-8'), '/');

        return preg_replace('/(' . $queryEscaped . ')/iu', '<mark>$1</mark>', $escaped);
    }

    private static function computeRelevance(array $item, string $query): float
    {
        $score = 0.0;
        $q = mb_strtolower($query);
        $type = $item['type'] ?? '';

        if ($type === 'member') {
            $score += 5;
            $name = mb_strtolower($item['title'] ?? '');
            if ($name === $q) {
                $score += 30;
            } elseif (mb_strpos($name, $q) === 0) {
                $score += 15;
            }
        }

        if ($type === 'incoming' || $type === 'outgoing') {
            $score += 3;
            $subject = mb_strtolower($item['subject'] ?? $item['title'] ?? '');
            if (mb_strpos($subject, $q) !== false) {
                $score += 10;
            }
            $number = mb_strtolower($item['number_label'] ?? '');
            if ($number && mb_strpos($number, $q) !== false) {
                $score += 8;
            }
        }

        if ($type === 'event') {
            $score += 2;
            $title = mb_strtolower($item['title'] ?? '');
            if (mb_strpos($title, $q) !== false) {
                $score += 10;
            }
        }

        if ($type === 'comment') {
            $score += 1;
        }

        if (!empty($item['date'])) {
            $score += 1;
        }

        // Релевантность MATCH...AGAINST (только на FULLTEXT-пути), с потолком,
        // чтобы не перевешивать точные совпадения имён/номеров.
        $ft = (float)($item['ft_score'] ?? 0);
        if ($ft > 0) {
            $score += min(12.0, $ft * 3.0);
        }

        return $score;
    }
}
