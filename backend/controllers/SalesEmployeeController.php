<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Input;
use App\Services\SalesEmployeeService;
use App\Utils\JsonResponse;
use App\Utils\Request;

/** Sales Office employee list. */
final class SalesEmployeeController
{
    public function index(Request $request): void
    {
        JsonResponse::success(['employees' => (new SalesEmployeeService())->list()]);
    }

    public function store(Request $request): void
    {
        JsonResponse::success(['employee' => (new SalesEmployeeService())->create($request->json())], 201);
    }

    public function update(Request $request): void
    {
        $id = Input::pathId($request->params, 'Employee not found.');
        JsonResponse::success(['employee' => (new SalesEmployeeService())->update($id, $request->json())]);
    }

    public function delete(Request $request): void
    {
        (new SalesEmployeeService())->delete(Input::pathId($request->params, 'Employee not found.'));
        JsonResponse::success(['deleted' => true]);
    }
}
