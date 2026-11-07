<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogService;
use App\Utils\HttpException;
use App\Utils\JsonResponse;
use App\Utils\Request;

final class AuditLogController
{
    /** GET /api/audit-logs */
    public function index(Request $request): void
    {
        JsonResponse::success((new AuditLogService())->list($request->user, $_GET));
    }

    /** GET /api/audit-logs/export */
    public function export(Request $request): void
    {
        $file = (new AuditLogService())->exportCsv($request->user, $_GET);
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
            header('Cache-Control: no-store');
        }
        echo $file['content'];
    }

    /** GET /api/audit-logs/{id} */
    public function show(Request $request): void
    {
        $id = $request->params['id'] ?? '';
        if (!ctype_digit($id) || (int) $id < 1) {
            throw HttpException::notFound('Audit log entry not found.');
        }
        JsonResponse::success(['item' => (new AuditLogService())->get($request->user, (int) $id)]);
    }
}
