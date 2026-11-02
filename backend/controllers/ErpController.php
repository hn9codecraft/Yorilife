<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CatalogueRepository;
use App\Repositories\MasterSheetRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SalesEntryRepository;
use App\Repositories\SalesOfficeRepository;
use App\Services\ErpPresenter;
use App\Services\SalesOfficeService;
use App\Services\Input;
use App\Services\MasterSheetService;
use App\Services\Presenter;
use App\Utils\JsonResponse;
use App\Utils\Request;

/** Read model for the business screens, plus financial years and Master Sheet saves. */
final class ErpController
{
    public function state(Request $request): void
    {
        $office = new SalesOfficeRepository();
        $products = new ProductRepository();
        $sheets = new MasterSheetRepository();
        $links = $products->linksByProduct();
        $presentedProducts = [];
        foreach ($products->all() as $row) {
            $id = (int) $row['id'];
            $presentedProducts[] = ErpPresenter::product($row, $links[$id]['tag'] ?? [], $links[$id]['badge'] ?? []);
        }

        $masterSheet = [];
        foreach ($sheets->entries() as $entry) {
            $masterSheet[$entry['year_label']][(string) $entry['product_id']] = [
                'inclusion' => $entry['inclusion'],
                'stripPack' => $entry['strip_pack'],
                'mrp' => self::money($entry['mrp']),
                'ptr' => self::money($entry['ptr']),
                'pts' => self::money($entry['pts']),
                'updatedAt' => Presenter::iso($entry['updated_at']),
            ];
        }

        $headquarters = array_map(ErpPresenter::headquarters(...), $office->headquarters());
        $managers = (new SalesOfficeService($office))->listedManagers();
        $stockists = array_map(ErpPresenter::stockist(...), $office->stockists());

        JsonResponse::success([
            'headquarters' => $headquarters,
            'managers' => $managers,
            'stockists' => $stockists,
            'locations' => (new SalesOfficeService($office))->listedLocations(),
            'hierarchy' => ErpPresenter::salesOfficeHierarchy($managers, $headquarters, $stockists),
            'terms' => array_map(ErpPresenter::term(...), (new CatalogueRepository())->all()),
            'products' => $presentedProducts,
            'productHistory' => ErpPresenter::productHistory($products->changes(), $sheets->changes()),
            'financialYears' => array_column($sheets->years(), 'label'),
            'masterSheet' => $masterSheet === [] ? new \stdClass() : $masterSheet,
            'masterSheetHistory' => array_map(ErpPresenter::sheetChange(...), $sheets->changes()),
            'sales' => array_map(ErpPresenter::sales(...), (new SalesEntryRepository())->all()),
        ]);
    }

    public function storeYear(Request $request): void
    {
        JsonResponse::success(['financialYear' => (new MasterSheetService())->createYear($request->user, $request->json())], 201);
    }

    public function saveSheet(Request $request): void
    {
        $productId = Input::pathId($request->params, 'Product not found.', 'productId');
        (new MasterSheetService())->saveEntry($request->user, (string) ($request->params['year'] ?? ''), $productId, $request->json());
        JsonResponse::success(['saved' => true]);
    }

    public function scanSheet(Request $request): void
    {
        JsonResponse::success((new MasterSheetService())->scan((string) ($request->params['year'] ?? '')));
    }

    public function updateSheetProducts(Request $request): void
    {
        JsonResponse::success((new MasterSheetService())->updateProducts($request->user, (string) ($request->params['year'] ?? '')));
    }

    public function addSheetProducts(Request $request): void
    {
        JsonResponse::success((new MasterSheetService())->addSelected($request->user, (string) ($request->params['year'] ?? ''), $request->json()));
    }

    private static function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
