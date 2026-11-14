<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\BonusCalcMath;
use App\Services\BonusCalcService;
use App\Services\Input;
use App\Utils\JsonResponse;
use App\Utils\Request;

/** Sales Management > Bonus Calc. Viewers use sales.view; edits use sales.manage. */
final class BonusCalcController
{
    public function index(Request $request): void
    {
        $year = $_GET['financialYear'] ?? BonusCalcMath::DEFAULT_YEAR;
        JsonResponse::success((new BonusCalcService())->list(is_string($year) ? $year : ''));
    }

    public function store(Request $request): void
    {
        JsonResponse::success((new BonusCalcService())->add($request->json()), 201);
    }

    public function update(Request $request): void
    {
        $id = Input::pathId($request->params, 'That Bonus Calc row was not found.');
        JsonResponse::success((new BonusCalcService())->reassign($id, $request->json()));
    }

    public function delete(Request $request): void
    {
        $id = Input::pathId($request->params, 'That Bonus Calc row was not found.');
        JsonResponse::success((new BonusCalcService())->remove($id));
    }

    public function showMonth(Request $request): void
    {
        JsonResponse::success((new BonusCalcService())->month(
            (string) ($request->params['financialYear'] ?? ''),
            Input::pathId($request->params, 'Employee not found.', 'employeeId'),
            (string) ($request->params['month'] ?? ''),
        ));
    }

    public function saveMonth(Request $request): void
    {
        JsonResponse::success((new BonusCalcService())->saveMonth(
            (string) ($request->params['financialYear'] ?? ''),
            Input::pathId($request->params, 'Employee not found.', 'employeeId'),
            (string) ($request->params['month'] ?? ''),
            $request->json(),
        ));
    }
}
