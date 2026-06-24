<?php

namespace App\Services;

/**
 * Pure assembly of the /api/health.php response. Extracted so the health
 * contract (which checks make the service "ok" vs "degraded", and the payload
 * shape) can be unit-tested directly instead of being reimplemented inside a test.
 */
final class HealthReport
{
    /**
     * The service is healthy only when the database is reachable and the uploads
     * directory is writable. Other checks/metrics are informational.
     *
     * @param array<string,bool> $checks
     */
    public static function isHealthy(array $checks): bool
    {
        return !empty($checks['database']) && !empty($checks['uploads_writable']);
    }

    /**
     * @param array<string,bool> $checks
     */
    public static function httpStatus(array $checks): int
    {
        return self::isHealthy($checks) ? 200 : 503;
    }

    /**
     * Build the JSON payload returned by the health endpoint.
     *
     * @param array<string,bool>  $checks
     * @param array<string,mixed> $metrics
     * @param string[]            $messages
     * @return array<string,mixed>
     */
    public static function build(array $checks, array $metrics = [], array $messages = []): array
    {
        return [
            'status'    => self::isHealthy($checks) ? 'ok' : 'degraded',
            'checks'    => $checks,
            'metrics'   => $metrics,
            'messages'  => $messages,
            'timestamp' => date('c'),
        ];
    }
}
