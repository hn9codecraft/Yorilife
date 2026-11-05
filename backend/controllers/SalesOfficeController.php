<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ErpPresenter;
use App\Services\Input;
use App\Services\SalesOfficeService;
use App\Repositories\SalesOfficeRepository;
use App\Utils\JsonResponse;
use App\Utils\Request;

final class SalesOfficeController
{
    public function headquarters(Request $request): void
    {
        $rows = (new SalesOfficeRepository())->headquarters();
        JsonResponse::success(['headquarters' => array_map(ErpPresenter::headquarters(...), $rows)]);
    }

    public function storeHeadquarters(Request $request): void
    {
        JsonResponse::success(['headquarters' => (new SalesOfficeService())->createHeadquarters($request->json())], 201);
    }

    public function updateHeadquarters(Request $request): void
    {
        $id = Input::pathId($request->params, 'Headquarters not found.');
        JsonResponse::success(['headquarters' => (new SalesOfficeService())->updateHeadquarters($id, $request->json())]);
    }

    public function deleteHeadquarters(Request $request): void
    {
        $id = Input::pathId($request->params, 'Headquarters not found.');
        (new SalesOfficeService())->deleteHeadquarters($id);
        JsonResponse::success(['deleted' => true]);
    }

    public function storeLocationHeadquarters(Request $request): void
    {
        JsonResponse::success(['locations' => (new SalesOfficeService())->createLocationHeadquarters($request->json())], 201);
    }

    public function updateLocationHeadquarters(Request $request): void
    {
        $id = Input::pathId($request->params, 'Location headquarters not found.');
        JsonResponse::success(['locations' => (new SalesOfficeService())->updateLocationHeadquarters($id, $request->json())]);
    }

    public function deleteLocationHeadquarters(Request $request): void
    {
        $id = Input::pathId($request->params, 'Location headquarters not found.');
        (new SalesOfficeService())->deleteLocationHeadquarters($id);
        JsonResponse::success(['deleted' => true]);
    }

    public function locations(Request $request): void
    {
        JsonResponse::success(['locations' => (new SalesOfficeService())->listedLocations()]);
    }

    public function storeLocation(Request $request): void
    {
        JsonResponse::success(['location' => (new SalesOfficeService())->createLocation($request->json())], 201);
    }

    public function updateLocation(Request $request): void
    {
        $id = Input::pathId($request->params, 'State not found.');
        JsonResponse::success(['location' => (new SalesOfficeService())->updateLocation($id, $request->json())]);
    }

    public function deleteLocation(Request $request): void
    {
        $id = Input::pathId($request->params, 'State not found.');
        (new SalesOfficeService())->deleteLocation($id);
        JsonResponse::success(['deleted' => true]);
    }

    public function managers(Request $request): void
    {
        JsonResponse::success(['managers' => (new SalesOfficeService())->listedManagers()]);
    }

    public function storeManager(Request $request): void
    {
        JsonResponse::success(['manager' => (new SalesOfficeService())->createManager($request->json())], 201);
    }

    public function updateManager(Request $request): void
    {
        $id = Input::pathId($request->params, 'Manager not found.');
        JsonResponse::success(['manager' => (new SalesOfficeService())->updateManager($id, $request->json())]);
    }

    public function deleteManager(Request $request): void
    {
        $id = Input::pathId($request->params, 'Manager not found.');
        (new SalesOfficeService())->deleteManager($id);
        JsonResponse::success(['deleted' => true]);
    }

    public function stockists(Request $request): void
    {
        $rows = (new SalesOfficeRepository())->stockists();
        JsonResponse::success(['stockists' => array_map(ErpPresenter::stockist(...), $rows)]);
    }

    public function storeStockist(Request $request): void
    {
        JsonResponse::success(['stockist' => (new SalesOfficeService())->createStockist($request->json())], 201);
    }

    public function updateStockist(Request $request): void
    {
        $id = Input::pathId($request->params, 'Stockist not found.');
        JsonResponse::success(['stockist' => (new SalesOfficeService())->updateStockist($id, $request->json())]);
    }

    public function deleteStockist(Request $request): void
    {
        $id = Input::pathId($request->params, 'Stockist not found.');
        (new SalesOfficeService())->deleteStockist($id);
        JsonResponse::success(['deleted' => true]);
    }
}
