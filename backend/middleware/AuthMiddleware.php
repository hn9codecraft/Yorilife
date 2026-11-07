<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\ApiTokenService;
use App\Services\AuthService;
use App\Services\Authorization;
use App\Services\RequestContext;
use App\Utils\HttpException;
use App\Utils\Request;

/**
 * Route guards. Use in routes/api.php:
 *   [AuthMiddleware::authenticated()]                       signed-in, active user
 *   [AuthMiddleware::authenticated(), AuthMiddleware::can('users.view')]
 * State-changing requests (POST/PUT/PATCH/DELETE) must also carry the
 * session's CSRF token in the X-CSRF-Token header.
 */
final class AuthMiddleware
{
    public static function authenticated(): callable
    {
        return static function (Request $request): void {
            RequestContext::boot($request->ip(), $request->header('User-Agent'));
            $bearer = self::bearer($request);
            if ($bearer !== null) {
                $user = (new ApiTokenService())->authenticate($bearer);
                RequestContext::setActor($user);
                $request->user = $user;
                $request->csrfToken = '';
                $request->twoFactor = 'ok';

                return;
            }

            $session = (new AuthService())->currentSession();
            RequestContext::setActor($session['user']);

            if ($request->isStateChanging()) {
                $token = $request->header('X-CSRF-Token') ?? '';
                if (!hash_equals($session['csrfToken'], $token)) {
                    throw HttpException::forbidden('Your security token is missing or out of date. Reload the page and try again.', 'csrf_token_invalid');
                }
            }

            $request->user = $session['user'];
            $request->csrfToken = $session['csrfToken'];
            $request->twoFactor = $session['twoFactor'];
            self::enforceTwoFactor($request);
        };
    }

    public static function can(string $permission): callable
    {
        return static function (Request $request) use ($permission): void {
            if ($request->user === null) {
                throw HttpException::unauthenticated();
            }
            Authorization::authorize($request->user, $permission);
        };
    }

    private static function bearer(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if ($header === null || preg_match('/^Bearer\s+(\S+)$/i', $header, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private static function enforceTwoFactor(Request $request): void
    {
        $state = $request->twoFactor;
        if ($state === 'ok') {
            return;
        }
        $allowed = $state === 'pending'
            ? ['/api/auth/me', '/api/auth/logout', '/api/auth/2fa', '/api/auth/2fa/verify', '/api/auth/2fa/recover']
            : ['/api/auth/me', '/api/auth/logout', '/api/auth/2fa', '/api/auth/2fa/setup', '/api/auth/2fa/confirm'];
        if (in_array($request->path, $allowed, true)) {
            return;
        }

        throw HttpException::forbidden(
            $state === 'pending'
                ? 'Enter the code from your authenticator app to continue.'
                : 'Set up an authenticator app to continue.',
            $state === 'pending' ? 'two_factor_required' : 'two_factor_enrollment_required',
        );
    }
}
