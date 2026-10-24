<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use Throwable;

/**
 * Checks MySQL connectivity. Failure details are written to the server error log only;
 * the returned array is safe to send to clients (no hostnames, users or driver messages).
 */
final class DatabaseHealthService
{
    /** @return array{status: string, latency_ms?: float, message?: string} */
    public function check(): array
    {
        if (!Database::isConfigured()) {
            return [
                'status' => 'not_configured',
                'message' => 'Database credentials are not configured.',
            ];
        }

        $started = hrtime(true);

        try {
            Database::connection()->query('SELECT 1')->fetchColumn();
        } catch (Throwable $e) {
            error_log(sprintf('[health] Database connection failed: %s', $e->getMessage()));

            return [
                'status' => 'unavailable',
                'message' => 'Database connection failed.',
            ];
        }

        return [
            'status' => 'connected',
            'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
        ];
    }
}
