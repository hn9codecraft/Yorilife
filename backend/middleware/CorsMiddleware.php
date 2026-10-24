<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Adds CORS headers for allowed origins and answers preflight (OPTIONS) requests.
 * Mobile apps do not send an Origin header, so they are unaffected.
 *
 * Credentials (the session cookie) are only allowed for the exact origins in
 * CORS_ALLOWED_ORIGINS — never a wildcard. In local development the React app
 * reaches the API through Vite's same-origin proxy, so CORS is not needed there.
 */
final class CorsMiddleware
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins)
    {
    }

    /** @return bool true when the request was fully handled (preflight) and must stop here */
    public function handle(string $method): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origin !== '' && in_array($origin, $this->allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Accept, X-CSRF-Token');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 600');
        }

        header('Vary: Origin');

        if (strtoupper($method) === 'OPTIONS') {
            http_response_code(204);
            return true;
        }

        return false;
    }
}
