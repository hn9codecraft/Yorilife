<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ApiTokenService;
use App\Services\TwoFactorService;
use App\Utils\HttpException;
use App\Utils\JsonResponse;
use App\Utils\Request;

/** Signed-in person's authenticator app and device sign-in keys. */
final class AccountSecurityController
{
    public function show(Request $request): void
    {
        JsonResponse::success((new TwoFactorService())->status($request->user));
    }

    public function setupTotp(Request $request): void
    {
        JsonResponse::success((new TwoFactorService())->beginSetup($request->user));
    }

    public function confirmTotp(Request $request): void
    {
        JsonResponse::success((new TwoFactorService())->confirmSetup($request->user, $request->json()));
    }

    public function disableTotp(Request $request): void
    {
        JsonResponse::success((new TwoFactorService())->disable($request->user, $request->json()));
    }

    public function recoveryCodes(Request $request): void
    {
        JsonResponse::success((new TwoFactorService())->regenerateRecoveryCodes($request->user, $request->json()));
    }

    public function tokens(Request $request): void
    {
        JsonResponse::success((new ApiTokenService())->list($request->user));
    }

    public function createToken(Request $request): void
    {
        JsonResponse::success((new ApiTokenService())->create($request->user, $request->json(), $request->ip()), 201);
    }

    public function revokeToken(Request $request): void
    {
        JsonResponse::success((new ApiTokenService())->revoke($request->user, self::id($request)));
    }

    private static function id(Request $request): int
    {
        $id = $request->params['id'] ?? '';
        if (!ctype_digit($id) || (int) $id < 1) {
            throw HttpException::notFound('Device not found.');
        }

        return (int) $id;
    }
}
