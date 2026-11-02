<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SearchService;
use App\Utils\JsonResponse;
use App\Utils\Request;

/** Signed-in global search. Which groups come back depends on the caller's permissions. */
final class SearchController
{
    public function index(Request $request): void
    {
        JsonResponse::success((new SearchService())->search($request->user ?? [], $request->query('q')));
    }
}
