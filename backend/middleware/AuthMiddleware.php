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
 * State-changing cookie requests must also carry the session's CSRF token
 * in the X-CSRF-Token header. A device key (Authorization: Bearer) is enough
 * on its own: the mobile app does not use cookies or a CSRF token.
 */
final class AuthMiddleware
{
    public static function authenticated(): callable
    {
        return static function (Request $request): void {
            $bearer = self::bearer($request);
            if ($bearer !== null) {
                $user = (new ApiTokenService())->authenticate($bearer);
                RequestContext::boot($request->ip(), $request->header('User-Agent'));
                RequestContext::setActor($user);
                $request->user = $user;
                $request->csrfToken = null;

                return;
            }

            $session = (new AuthService())->currentSession();
            RequestContext::boot($request->ip(), $request->header('User-Agent'));
            RequestContext::setActor($session['user']);

            if ($request->isStateChanging()) {
                $token = $request->header('X-CSRF-Token') ?? '';
                if (!hash_equals($session['csrfToken'], $token)) {
                    throw HttpException::forbidden('Your security token is missing or out of date. Reload the page and try again.', 'csrf_token_invalid');
                }
            }

            $request->user = $session['user'];
            $request->csrfToken = $session['csrfToken'];
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
        if (!is_string($header) || preg_match('/^Bearer\s+(\S+)$/i', $header, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
