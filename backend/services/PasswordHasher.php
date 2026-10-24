<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Password hashing via PHP's password_* functions (bcrypt, cost 12).
 * No custom cryptography.
 */
final class PasswordHasher
{
    private const ALGORITHM = PASSWORD_BCRYPT;
    private const OPTIONS = ['cost' => 12];

    /**
     * A real cost-12 hash of a random, discarded value. Verifying against it
     * for unknown emails makes sign-in take the same time whether or not the
     * account exists.
     */
    private const DUMMY_HASH = '$2y$12$MOUup7842ge1.4wlMeJmLuN/xzJUpapvhaDVx8UbpIfPECQEcnZMG';

    public static function hash(string $password): string
    {
        return password_hash($password, self::ALGORITHM, self::OPTIONS);
    }

    public static function verify(string $password, ?string $hash): bool
    {
        return password_verify($password, $hash ?? self::DUMMY_HASH) && $hash !== null;
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::ALGORITHM, self::OPTIONS);
    }
}
