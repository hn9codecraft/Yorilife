<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;
use App\Services\Authorization;
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
            $session = (new AuthService())->currentSession();

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
}
