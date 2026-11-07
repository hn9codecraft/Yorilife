<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\TwoFactorService;
use App\Services\UserPresenter;
use App\Utils\JsonResponse;
use App\Utils\Request;

final class TwoFactorController
{
    /** GET /api/auth/2fa */
    public function status(Request $request): void
    {
        JsonResponse::success((new TwoFactorService())->status($request->user));
    }

    /** POST /api/auth/2fa/setup */
    public function setup(Request $request): void
    {
        $request->json();
        JsonResponse::success((new TwoFactorService())->setup($request->user));
    }

    /** POST /api/auth/2fa/confirm { code } */
    public function confirm(Request $request): void
    {
        $result = (new TwoFactorService())->confirm($request->user, $request->json());

        JsonResponse::success($result);
    }

    /** POST /api/auth/2fa/verify { code } */
    public function verify(Request $request): void
    {
        $user = (new TwoFactorService())->verify($request->user, $request->json());

        JsonResponse::success([
            'user' => UserPresenter::presentSelf($user),
            'csrfToken' => $request->csrfToken,
            'twoFactor' => 'ok',
        ]);
    }

    /** POST /api/auth/2fa/recover { code } */
    public function recover(Request $request): void
    {
        $user = (new TwoFactorService())->recover($request->user, $request->json());

        JsonResponse::success([
            'user' => UserPresenter::presentSelf($user),
            'csrfToken' => $request->csrfToken,
            'twoFactor' => 'ok',
        ]);
    }

    /** POST /api/auth/2fa/disable { password, code } */
    public function disable(Request $request): void
    {
        JsonResponse::success((new TwoFactorService())->disable($request->user, $request->json()));
    }
}
