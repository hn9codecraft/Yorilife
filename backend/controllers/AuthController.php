<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\UserPresenter;
use App\Utils\JsonResponse;
use App\Utils\Request;

final class AuthController
{
    /** POST /api/auth/login { email, password } — starts a session (HttpOnly cookie). */
    public function login(Request $request): void
    {
        $result = (new AuthService())->login($request->json(), $request->ip());

        JsonResponse::success([
            'user' => UserPresenter::presentSelf($result['user']),
            'csrfToken' => $result['csrfToken'],
            'twoFactor' => $result['twoFactor'],
        ]);
    }

    /** POST /api/auth/logout — destroys the server-side session and expires the cookie. */
    public function logout(Request $request): void
    {
        (new AuthService())->logout();

        JsonResponse::success(['signedOut' => true]);
    }

    /** GET /api/auth/me — the signed-in user, their permissions and the CSRF token. */
    public function me(Request $request): void
    {
        JsonResponse::success([
            'user' => UserPresenter::presentSelf($request->user),
            'csrfToken' => $request->csrfToken,
            'twoFactor' => $request->twoFactor,
        ]);
    }
}
