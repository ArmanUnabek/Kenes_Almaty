<?php

namespace App\Services;

/**
 * Ordering for global search results. Rows from incoming/outgoing letters and
 * members are merged, then ranked newest-first by date, with the subject used as
 * a stable tie-break (descending). Extracted from api/search.php so the ordering
 * can be unit-tested directly.
 */
final class SearchRanker
{
    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    public static function sort(array $items): array
    {
        usort($items, [self::class, 'compare']);
        return $items;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    public static function compare(array $a, array $b): int
    {
        $dateA = (string)($a['date'] ?? '');
        $dateB = (string)($b['date'] ?? '');
        if ($dateA === $dateB) {
            return strcmp((string)($b['subject'] ?? ''), (string)($a['subject'] ?? ''));
        }
        return strcmp($dateB, $dateA);
    }
}
