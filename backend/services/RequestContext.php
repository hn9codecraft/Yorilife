<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Per-request context for audit logging: actor, IP, user agent and a request id.
 * Set once at the start of a request (and after authentication). Never stores
 * passwords or tokens.
 */
final class RequestContext
{
    private static ?string $requestId = null;
    private static ?string $ip = null;
    private static ?string $userAgent = null;
    /** @var array<string, mixed>|null */
    private static ?array $actor = null;

    public static function boot(?string $ip = null, ?string $userAgent = null): void
    {
        self::$requestId ??= self::uuid();
        self::$ip = $ip !== null && $ip !== '' ? substr($ip, 0, 45) : self::$ip;
        if ($userAgent !== null) {
            self::$userAgent = $userAgent === '' ? null : substr($userAgent, 0, 255);
        }
    }

    /** @param array<string, mixed>|null $actor row from UserRepository::find(), or null */
    public static function setActor(?array $actor): void
    {
        self::$actor = $actor;
    }

    public static function requestId(): string
    {
        return self::$requestId ??= self::uuid();
    }

    public static function ip(): ?string
    {
        return self::$ip;
    }

    public static function userAgent(): ?string
    {
        return self::$userAgent;
    }

    /** @return array<string, mixed>|null */
    public static function actor(): ?array
    {
        return self::$actor;
    }

    public static function actorId(): ?int
    {
        return isset(self::$actor['id']) ? (int) self::$actor['id'] : null;
    }

    public static function actorLabel(): ?string
    {
        if (self::$actor === null) {
            return null;
        }

        return isset(self::$actor['name']) ? (string) self::$actor['name'] : null;
    }

    /** Test / CLI helper: clear static state between requests. */
    public static function reset(): void
    {
        self::$requestId = null;
        self::$ip = null;
        self::$userAgent = null;
        self::$actor = null;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
