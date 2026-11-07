<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SettingsService;
use App\Utils\JsonResponse;
use App\Utils\Request;

final class SettingsController
{
    /** GET /api/settings */
    public function index(Request $request): void
    {
        JsonResponse::success((new SettingsService())->view($request->user));
    }

    /** PATCH /api/settings { values } */
    public function update(Request $request): void
    {
        JsonResponse::success((new SettingsService())->update($request->user, $request->json()));
    }

    /** GET /api/settings/history */
    public function history(Request $request): void
    {
        JsonResponse::success((new SettingsService())->history($request->user, $_GET));
    }
}
