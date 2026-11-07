<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\UserPresenter;
use App\Utils\JsonResponse;
use App\Utils\Request;

final class AuthController
{
    /** POST /api/auth/login { email, password } — starts a session, or a second-step challenge. */
    public function login(Request $request): void
    {
        $result = (new AuthService())->login($request->json(), $request->ip());
        if (isset($result['challenge'])) {
            $purpose = $result['challenge']['purpose'];
            JsonResponse::success([
                'twoFactorRequired' => $purpose === 'totp',
                'enrollmentRequired' => $purpose === 'enroll',
                'challengeToken' => $result['challenge']['token'],
                'expiresIn' => $result['challenge']['expiresIn'],
            ]);

            return;
        }

        JsonResponse::success([
            'user' => UserPresenter::presentSelf($result['user']),
            'csrfToken' => $result['csrfToken'],
        ]);
    }

    /** POST /api/auth/totp { challengeToken, code } — authenticator or recovery code. */
    public function totp(Request $request): void
    {
        $result = (new AuthService())->finishTwoFactor($request->json(), $request->ip());
        JsonResponse::success([
            'user' => UserPresenter::presentSelf($result['user']),
            'csrfToken' => $result['csrfToken'],
        ]);
    }

    /** POST /api/auth/totp/enroll { challengeToken } — secret for a required first-time setup. */
    public function enroll(Request $request): void
    {
        JsonResponse::success((new \App\Services\TwoFactorService())->beginEnrollment($request->json()));
    }

    /** POST /api/auth/totp/enroll/confirm { challengeToken, code } — enables 2FA and starts the session. */
    public function enrollConfirm(Request $request): void
    {
        $result = (new AuthService())->finishEnrollment($request->json(), $request->ip());
        JsonResponse::success([
            'user' => UserPresenter::presentSelf($result['user']),
            'csrfToken' => $result['csrfToken'],
            'recoveryCodes' => $result['recoveryCodes'],
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
        ]);
    }
}
