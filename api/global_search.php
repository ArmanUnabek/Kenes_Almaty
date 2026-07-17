<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';

use App\ApiController;
use App\Services\GlobalSearchService;

class GlobalSearchController extends ApiController
{
    public function handle(): void
    {
        try {
            $this->requireAuth();

            $q = trim((string)$this->getQueryParam('q', ''));
            $limit = max(1, min(50, (int)$this->getQueryParam('limit', 20)));

            if ($q === '') {
                $this->json(['results' => [], 'query' => '']);
                return;
            }

            $regionId = $this->resolveRegionIdForRead();
            $userId = (int)($this->getCurrentUser()['id'] ?? 0);

            $service = new GlobalSearchService($this->db);
            $raw = $service->search($q, $userId, $regionId, $limit);

            $results = [];
            foreach ($raw as $item) {
                $snippetText = $service::buildSnippet($item['snippet_source'] ?? '', $q);
                $highlighted = $service::highlightMatch($snippetText, $q);

                $results[] = [
                    'type'            => $item['type'],
                    'id'              => (int)$item['id'],
                    'title'           => $item['title'] ?? $item['subject'] ?? $item['full_name'] ?? '—',
                    'snippet'         => $highlighted,
                    'url'             => $item['url'] ?? '',
                    'relevance_score' => (float)($item['relevance_score'] ?? 0),
                    'date'            => $item['date'] ?? null,
                    'meta'            => self::buildMeta($item),
                ];
            }

            $this->json([
                'results' => $results,
                'query'   => $q,
                'total'   => count($results),
            ]);
        } catch (\Throwable $e) {
            $this->handleException($e, 'GlobalSearchController');
        }
    }

    private static function buildMeta(array $item): array
    {
        $type = $item['type'] ?? '';
        $meta = [];

        if ($type === 'incoming' || $type === 'outgoing') {
            $meta['number'] = $item['number_label'] ?? '';
            $meta['organization'] = $item['organization'] ?? '';
        } elseif ($type === 'member') {
            $meta['position'] = $item['position'] ?? '';
            $meta['organization'] = $item['organization'] ?? '';
            $meta['commission'] = $item['commission_name'] ?? '';
        } elseif ($type === 'event') {
            $meta['location'] = $item['location'] ?? '';
            $meta['participants'] = (int)($item['participants_total'] ?? 0);
        } elseif ($type === 'comment') {
            $meta['letter_type'] = $item['letter_type'] ?? '';
            $meta['letter_id'] = (int)($item['letter_id'] ?? 0);
            $meta['user_name'] = $item['user_name'] ?? '';
        }

        return $meta;
    }
}

$controller = new GlobalSearchController();
$controller->handle();
