<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ApiTokenService;
use App\Utils\HttpException;
use App\Utils\JsonResponse;
use App\Utils\Request;

final class ApiTokenController
{
    /** GET /api/auth/tokens */
    public function index(Request $request): void
    {
        JsonResponse::success(['tokens' => (new ApiTokenService())->list($request->user)]);
    }

    /** POST /api/auth/tokens { name, expiresInDays? } — the token value is returned once. */
    public function store(Request $request): void
    {
        JsonResponse::success((new ApiTokenService())->create($request->user, $request->json()), 201);
    }

    /** POST /api/auth/tokens/{id}/revoke */
    public function revoke(Request $request): void
    {
        $request->json();
        JsonResponse::success(['apiToken' => (new ApiTokenService())->revoke($request->user, self::id($request))]);
    }

    private static function id(Request $request): int
    {
        $id = $request->params['id'] ?? '';
        if (!ctype_digit($id) || (int) $id < 1) {
            throw HttpException::notFound('Device token not found.');
        }

        return (int) $id;
    }
}
