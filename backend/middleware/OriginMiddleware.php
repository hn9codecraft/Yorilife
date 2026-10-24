<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Utils\HttpException;
use App\Utils\Request;

/**
 * Defence in depth against cross-site requests: a browser request that changes
 * state must come from an allowed origin (the API's own APP_URL or one listed
 * in CORS_ALLOWED_ORIGINS). Requests without an Origin header (curl, server
 * jobs, the future mobile app) are not affected; they still need a session
 * and CSRF token for protected endpoints.
 */
final class OriginMiddleware
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins)
    {
    }

    public function handle(Request $request): void
    {
        $origin = $request->header('Origin');
        if (!$request->isStateChanging() || $origin === null || $origin === '') {
            return;
        }

        if (!in_array(rtrim($origin, '/'), $this->allowedOrigins, true)) {
            throw HttpException::forbidden('Requests from this origin are not allowed.', 'origin_not_allowed');
        }
    }
}
