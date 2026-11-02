<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SalesEntryRepository;
use App\Services\ErpPresenter;
use App\Services\Input;
use App\Services\SalesEntryService;
use App\Utils\JsonResponse;
use App\Utils\Request;

final class SalesEntryController
{
    public function index(Request $request): void
    {
        JsonResponse::success(['sales' => array_map(ErpPresenter::sales(...), (new SalesEntryRepository())->all())]);
    }

    public function store(Request $request): void
    {
        JsonResponse::success(['sale' => (new SalesEntryService())->create($request->user, $request->json())], 201);
    }

    public function update(Request $request): void
    {
        $id = Input::pathId($request->params, 'Sales entry not found.');
        JsonResponse::success(['sale' => (new SalesEntryService())->update($id, $request->json())]);
    }
}
