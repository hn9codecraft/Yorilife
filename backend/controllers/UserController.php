<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\UserPresenter;
use App\Services\UserService;
use App\Utils\HttpException;
use App\Utils\JsonResponse;
use App\Utils\Request;

/** User management. Authorization rules live in UserService / Authorization. */
final class UserController
{
    /** GET /api/users */
    public function index(Request $request): void
    {
        $users = (new UserService())->list($request->user);

        JsonResponse::success(['users' => array_map([UserPresenter::class, 'present'], $users)]);
    }

    /** GET /api/users/{id} */
    public function show(Request $request): void
    {
        JsonResponse::success(['user' => UserPresenter::present((new UserService())->get($request->user, self::id($request)))]);
    }

    /** POST /api/users { name, email, role, password } */
    public function store(Request $request): void
    {
        $user = (new UserService())->create($request->user, $request->json());

        JsonResponse::success(['user' => UserPresenter::present($user)], 201);
    }

    /** PATCH /api/users/{id} any of { name, email, role, status } */
    public function update(Request $request): void
    {
        $user = (new UserService())->update($request->user, self::id($request), $request->json());

        JsonResponse::success(['user' => UserPresenter::present($user)]);
    }

    /** GET /api/roles */
    public function roles(Request $request): void
    {
        JsonResponse::success(['roles' => (new UserService())->roles($request->user)]);
    }

    private static function id(Request $request): int
    {
        $id = $request->params['id'] ?? '';
        if (!ctype_digit($id) || (int) $id < 1) {
            throw HttpException::notFound('User not found.');
        }

        return (int) $id;
    }
}
