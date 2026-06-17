<?php

namespace App\Services;

class RegionService
{
    public static function parseSettings(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public static function getSeqBaseline(\PDO $db, int $regionId, string $table): int
    {
        $stmt = $db->prepare('SELECT settings FROM regions WHERE id = ?');
        $stmt->execute([$regionId]);
        $row = $stmt->fetch();
        $settings = self::parseSettings($row['settings'] ?? null);

        $key = $table === 'incoming_letters' ? 'seq_baseline_incoming' : 'seq_baseline_outgoing';
        if (array_key_exists($key, $settings)) {
            return max(0, (int)$settings[$key]);
        }

        if ($regionId === 1) {
            return $table === 'incoming_letters' ? 1327 : 1399;
        }

        return 0;
    }

    /**
     * @return array{commissions_copied:int,settings:array}
     */
    public static function bootstrap(
        \PDO $db,
        int $regionId,
        ?int $templateRegionId = 1,
        array $options = []
    ): array {
        $stmt = $db->prepare('SELECT * FROM regions WHERE id = ?');
        $stmt->execute([$regionId]);
        $region = $stmt->fetch();
        if (!$region) {
            throw new \InvalidArgumentException('Регион не найден');
        }

        $settings = self::parseSettings($region['settings'] ?? null);
        $settings['seq_baseline_incoming'] = max(0, (int)($options['seq_baseline_incoming'] ?? $settings['seq_baseline_incoming'] ?? 0));
        $settings['seq_baseline_outgoing'] = max(0, (int)($options['seq_baseline_outgoing'] ?? $settings['seq_baseline_outgoing'] ?? 0));

        $copyCommissions = !array_key_exists('copy_commissions', $options) || !empty($options['copy_commissions']);
        $commissionsCopied = 0;

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE regions SET is_active = TRUE, settings = ? WHERE id = ?')
                ->execute([json_encode($settings, JSON_ENCODE_FLAGS), $regionId]);

            if ($copyCommissions && $templateRegionId && $templateRegionId !== $regionId) {
                $countStmt = $db->prepare('SELECT COUNT(*) FROM commissions WHERE region_id = ?');
                $countStmt->execute([$regionId]);
                if ((int)$countStmt->fetchColumn() === 0) {
                    $tpl = $db->prepare('SELECT name, description, color, sort_order FROM commissions WHERE region_id = ? ORDER BY sort_order, name');
                    $tpl->execute([$templateRegionId]);
                    $insert = $db->prepare('
                        INSERT INTO commissions (region_id, name, description, color, sort_order)
                        VALUES (?, ?, ?, ?, ?)
                    ');
                    foreach ($tpl->fetchAll() as $row) {
                        $insert->execute([
                            $regionId,
                            $row['name'],
                            $row['description'],
                            $row['color'] ?? '#0d6efd',
                            (int)($row['sort_order'] ?? 0),
                        ]);
                        $commissionsCopied++;
                    }
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'commissions_copied' => $commissionsCopied,
            'settings' => $settings,
        ];
    }
}
