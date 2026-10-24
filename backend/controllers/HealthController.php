<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DatabaseHealthService;
use App\Utils\JsonResponse;

final class HealthController
{
    /** GET /api/health — API liveness; does not touch the database. */
    public function index(): void
    {
        $app = require dirname(__DIR__) . '/config/app.php';

        JsonResponse::success([
            'status' => 'ok',
            'service' => $app['name'],
            'version' => $app['version'],
            'environment' => $app['env'],
            'timestamp' => date(DATE_ATOM),
        ]);
    }

    /** GET /api/health/database — MySQL connectivity check. */
    public function database(): void
    {
        $result = (new DatabaseHealthService())->check();

        if ($result['status'] === 'connected') {
            JsonResponse::success(['database' => $result]);
            return;
        }

        JsonResponse::error('database_' . $result['status'], $result['message'], 503);
    }
}
