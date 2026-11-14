<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Input;
use App\Services\ProductCategoryService;
use App\Utils\JsonResponse;
use App\Utils\Request;

/** Product Type and Product Group (Products > Category). */
final class ProductCategoryController
{
    public function index(Request $request): void
    {
        JsonResponse::success([
            'productTypes' => (new ProductCategoryService('type'))->list(),
            'productGroups' => (new ProductCategoryService('group'))->list(),
        ]);
    }

    public function storeType(Request $request): void
    {
        JsonResponse::success(['productType' => (new ProductCategoryService('type'))->create($request->json())], 201);
    }

    public function updateType(Request $request): void
    {
        $id = Input::pathId($request->params, 'Product type not found.');
        JsonResponse::success(['productType' => (new ProductCategoryService('type'))->update($id, $request->json())]);
    }

    public function deleteType(Request $request): void
    {
        $id = Input::pathId($request->params, 'Product type not found.');
        JsonResponse::success(['productType' => (new ProductCategoryService('type'))->delete($id)]);
    }

    public function storeGroup(Request $request): void
    {
        JsonResponse::success(['productGroup' => (new ProductCategoryService('group'))->create($request->json())], 201);
    }

    public function updateGroup(Request $request): void
    {
        $id = Input::pathId($request->params, 'Product group not found.');
        JsonResponse::success(['productGroup' => (new ProductCategoryService('group'))->update($id, $request->json())]);
    }

    public function deleteGroup(Request $request): void
    {
        $id = Input::pathId($request->params, 'Product group not found.');
        JsonResponse::success(['productGroup' => (new ProductCategoryService('group'))->delete($id)]);
    }
}