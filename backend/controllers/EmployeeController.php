<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EmployeeService;
use App\Services\Input;
use App\Utils\JsonResponse;
use App\Utils\Request;

/** Employee list for announcements. */
final class EmployeeController
{
    public function index(Request $request): void
    {
        JsonResponse::success(['employees' => (new EmployeeService())->list()]);
    }

    public function store(Request $request): void
    {
        JsonResponse::success(['employee' => (new EmployeeService())->create($request->json(), $request->user)], 201);
    }

    public function update(Request $request): void
    {
        JsonResponse::success(['employee' => (new EmployeeService())->update(Input::pathId($request->params, 'Employee not found.'), $request->json(), $request->user)]);
    }

    public function delete(Request $request): void
    {
        (new EmployeeService())->delete(Input::pathId($request->params, 'Employee not found.'), $request->user);
        JsonResponse::success(['deleted' => true]);
    }
}