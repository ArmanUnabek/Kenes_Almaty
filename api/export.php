<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../src/ApiController.php';

use App\ApiController;
use App\Middleware\RateLimiter;
use App\Services\SecurityAuditService;
use App\Services\SpreadsheetExporter;

class ExportController extends ApiController
{
    public function handle(): void
    {
        try {
            $this->requireRole(['admin']);
            $userId = (int)($this->currentUser['id'] ?? 0);

            $format = strtolower((string)$this->getQueryParam('format', 'json'));
            if (!in_array($format, ['json', 'csv', 'xlsx'], true)) {
                $this->error('Формат должен быть json, csv или xlsx', 400);
            }

            RateLimiter::requireCheck(
                'export_user_' . $userId,
                SecurityAuditService::EXPORT_RATE_LIMIT,
                SecurityAuditService::EXPORT_RATE_WINDOW
            );

            $regionId = $this->getCurrentRegionId();
            $archived = $this->getQueryParam('archived') === '1';

            [$incoming, $outgoing] = $this->fetchLetters($regionId, $archived);

            SecurityAuditService::logExport($this->db, $userId, $regionId, $format, count($incoming), count($outgoing));

            $filename = 'os_journal_' . ($archived ? 'archive_' : '') . date('Y-m-d');

            if ($format === 'json') {
                $this->outputJson($incoming, $outgoing, $regionId, $filename);
            } elseif ($format === 'csv') {
                $this->outputCsv($incoming, $outgoing, $filename);
            } else {
                $this->outputXlsx($incoming, $outgoing, $filename);
            }
        } catch (\Throwable $e) {
            $this->handleException($e, 'ExportController');
        }
    }

    /**
     * @return array{0: array, 1: array} [incoming, outgoing]
     */
    private function fetchLetters(?int $regionId, bool $archived): array
    {
        $conditions = [];
        $conditions[] = $archived
            ? "(deleted_at IS NOT NULL AND deleted_at != '0000-00-00 00:00:00')"
            : "(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
        if ($regionId) {
            $conditions[] = 'region_id = ?';
        }
        $where = ' WHERE ' . implode(' AND ', $conditions);
        $params = $regionId ? [$regionId] : [];

        try {
            $stmt = $this->db->prepare('SELECT * FROM incoming_letters' . $where . ' ORDER BY date DESC, seq DESC');
            $stmt->execute($params);
            $incoming = $stmt->fetchAll();

            $stmt = $this->db->prepare('SELECT * FROM outgoing_letters' . $where . ' ORDER BY date DESC, seq DESC');
            $stmt->execute($params);
            $outgoing = $stmt->fetchAll();
        } catch (\Throwable $e) {
            // deleted_at column not yet created
            if ($archived) {
                // Archive can't be distinguished without the column — return nothing
                return [[], []];
            }
            $fallbackWhere = $regionId ? ' WHERE region_id = ?' : '';
            $stmt = $this->db->prepare('SELECT * FROM incoming_letters' . $fallbackWhere . ' ORDER BY date DESC, seq DESC');
            $stmt->execute($params);
            $incoming = $stmt->fetchAll();

            $stmt = $this->db->prepare('SELECT * FROM outgoing_letters' . $fallbackWhere . ' ORDER BY date DESC, seq DESC');
            $stmt->execute($params);
            $outgoing = $stmt->fetchAll();
        }

        return [$incoming, $outgoing];
    }

    private function outputJson(array $incoming, array $outgoing, ?int $regionId, string $filename): void
    {
        $payload = [
            'exported_at' => date('c'),
            'exported_by' => $this->currentUser['username'] ?? null,
            'region_id' => $regionId,
            'incoming' => $incoming,
            'outgoing' => $outgoing,
        ];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        echo json_encode($payload, JSON_ENCODE_FLAGS | JSON_PRETTY_PRINT);
        exit;
    }

    private function outputCsv(array $incoming, array $outgoing, string $filename): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF");

        fputcsv($out, ['Тип', 'РегНомер', 'Дата', 'Организация', 'Категория', 'Номер', 'Тема', 'Примечание'], ';');
        foreach ($incoming as $row) {
            fputcsv($out, [
                'Входящее',
                'Вх.' . ($row['seq'] ?? ''),
                $row['date'] ?? '',
                $row['organization'] ?? '',
                $row['category'] ?? 'KK',
                $row['kk_number'] ?? '',
                $row['subject'] ?? '',
                $row['note'] ?? '',
            ], ';');
        }

        fputcsv($out, [], ';');
        fputcsv($out, ['Тип', 'Порядк№', 'Дата', 'Исходящий№', 'Организация', 'Тема', 'Примечание'], ';');
        foreach ($outgoing as $row) {
            fputcsv($out, [
                'Исходящее',
                'Исх.' . ($row['seq'] ?? ''),
                $row['date'] ?? '',
                $row['outgoing_number'] ?? '',
                $row['organization'] ?? '',
                $row['subject'] ?? '',
                $row['note'] ?? '',
            ], ';');
        }
        fclose($out);
        exit;
    }

    private function outputXlsx(array $incoming, array $outgoing, string $filename): void
    {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        echo SpreadsheetExporter::build($incoming, $outgoing);
        exit;
    }
}

$controller = new ExportController();
$controller->handle();
