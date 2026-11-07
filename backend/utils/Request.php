<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * The current HTTP request: method, path parameters, headers, JSON body and
 * (after authentication middleware) the signed-in user.
 */
final class Request
{
    private const MAX_BODY_BYTES = 65536;

    /** @var array<string, string> */
    public array $params = [];

    /** Signed-in user (set by AuthMiddleware), shaped by UserRepository::find(). */
    public ?array $user = null;

    /** The session's CSRF token (set by AuthMiddleware). Empty for device-token calls. */
    public ?string $csrfToken = null;

    /** ok, pending (code required) or enroll (authenticator setup required). */
    public string $twoFactor = 'ok';

    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
    ) {
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? ($name === 'Content-Type' ? ($_SERVER['CONTENT_TYPE'] ?? null) : null);

        return is_string($value) ? $value : null;
    }

    /** Client IP. Uses REMOTE_ADDR only; proxy headers are not trusted (see docs). */
    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** One query-string value, or null when it is absent. Arrays are returned as-is so the caller can reject them. */
    public function query(string $name): mixed
    {
        return $_GET[$name] ?? null;
    }

    public function isStateChanging(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * JSON object body. Requiring application/json also blocks classic HTML-form
     * CSRF, because browsers can't send that content type cross-site without a
     * CORS preflight.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        $contentType = strtolower($this->header('Content-Type') ?? '');
        if (!str_starts_with($contentType, 'application/json')) {
            throw new HttpException(415, 'unsupported_media_type', 'Send the request body as JSON (Content-Type: application/json).');
        }

        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'payload_too_large', 'The request body is too large.');
        }

        try {
            $data = json_decode($raw === '' ? '{}' : $raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'invalid_json', 'The request body is not valid JSON.');
        }

        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new HttpException(400, 'invalid_json', 'The request body must be a JSON object.');
        }

        return $this->json = $data;
    }
}
