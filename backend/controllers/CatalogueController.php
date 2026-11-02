<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CatalogueRepository;
use App\Services\CatalogueService;
use App\Services\ErpPresenter;
use App\Services\Input;
use App\Utils\JsonResponse;
use App\Utils\Request;

final class CatalogueController
{
    public function index(Request $request): void
    {
        $rows = (new CatalogueRepository())->all();
        JsonResponse::success(['terms' => array_map(ErpPresenter::term(...), $rows)]);
    }

    public function store(Request $request): void
    {
        JsonResponse::success(['term' => (new CatalogueService())->create($request->json())], 201);
    }

    public function update(Request $request): void
    {
        $id = Input::pathId($request->params, 'Catalogue term not found.');
        JsonResponse::success(['term' => (new CatalogueService())->update($id, $request->json())]);
    }

    public function delete(Request $request): void
    {
        $id = Input::pathId($request->params, 'Catalogue term not found.');
        JsonResponse::success(['term' => (new CatalogueService())->delete($id)]);
    }

    public function restore(Request $request): void
    {
        $id = Input::pathId($request->params, 'Catalogue term not found.');
        JsonResponse::success(['term' => (new CatalogueService())->restore($id)]);
    }
}
