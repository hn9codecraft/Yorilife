<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\MasterSheetRepository;
use App\Repositories\ProductRepository;
use App\Services\ErpPresenter;
use App\Services\Input;
use App\Services\ProductService;
use App\Utils\JsonResponse;
use App\Utils\Request;

final class ProductController
{
    public function index(Request $request): void
    {
        $records = new ProductRepository();
        $links = $records->linksByProduct();
        $products = [];
        foreach ($records->all() as $row) {
            $id = (int) $row['id'];
            $products[] = ErpPresenter::product($row, $links[$id]['tag'] ?? [], $links[$id]['badge'] ?? []);
        }
        JsonResponse::success([
            'products' => $products,
            'productHistory' => ErpPresenter::productHistory($records->changes(), (new MasterSheetRepository())->changes()),
        ]);
    }

    public function store(Request $request): void
    {
        JsonResponse::success(['product' => (new ProductService())->create($request->user, $request->json())], 201);
    }

    public function update(Request $request): void
    {
        $id = Input::pathId($request->params, 'Product not found.');
        JsonResponse::success(['product' => (new ProductService())->update($request->user, $id, $request->json())]);
    }

    public function delete(Request $request): void
    {
        $id = Input::pathId($request->params, 'Product not found.');
        JsonResponse::success(['product' => (new ProductService())->delete($request->user, $id)]);
    }

    public function restore(Request $request): void
    {
        $id = Input::pathId($request->params, 'Product not found.');
        JsonResponse::success(['product' => (new ProductService())->restore($request->user, $id)]);
    }
}
