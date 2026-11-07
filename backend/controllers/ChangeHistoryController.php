<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ChangeHistoryService;
use App\Utils\JsonResponse;
use App\Utils\Request;

final class ChangeHistoryController
{
    /** GET /api/change-history */
    public function index(Request $request): void
    {
        JsonResponse::success((new ChangeHistoryService())->list($request->user, $_GET));
    }

    /** POST /api/change-history/archive */
    public function archive(Request $request): void
    {
        JsonResponse::success((new ChangeHistoryService())->archive($request->user, $request->json()));
    }

    /** GET /api/change-history/export */
    public function export(Request $request): void
    {
        $file = (new ChangeHistoryService())->exportCsv($request->user, $_GET);
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
            header('Cache-Control: no-store');
        }
        echo $file['content'];
    }
}
