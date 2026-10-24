<?php

declare(strict_types=1);

namespace App\Utils;

use RuntimeException;

/**
 * Thrown anywhere during a request to stop and send a JSON error response
 * (caught by Router::dispatch). The message is shown to the client, so it
 * must never contain internal details.
 */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, mixed>  $details  e.g. field validation errors
     * @param array<string, string> $headers  extra response headers (e.g. Retry-After)
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function unauthenticated(string $message = 'Please sign in to continue.', string $code = 'unauthenticated'): self
    {
        return new self(401, $code, $message);
    }

    public static function forbidden(string $message = 'You do not have permission to do this.', string $code = 'forbidden'): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    /** @param array<string, string> $fieldErrors field => message */
    public static function validation(array $fieldErrors, string $message = 'Some fields are invalid.'): self
    {
        return new self(422, 'validation_failed', $message, ['fields' => $fieldErrors]);
    }
}
