<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Sends JSON responses using the standard API envelope:
 *   success: { "success": true,  "data": {...} }
 *   error:   { "success": false, "error": { "code": "...", "message": "..." } }
 */
final class JsonResponse
{
    public static function success(mixed $data, int $status = 200): void
    {
        self::send(['success' => true, 'data' => $data], $status);
    }

    public static function error(string $code, string $message, int $status, array $details = []): void
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        self::send(['success' => false, 'error' => $error], $status);
    }

    public static function send(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
