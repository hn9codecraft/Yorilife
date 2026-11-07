<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The only shape in which users leave the API. Whitelists fields, so hashes or
 * future sensitive columns can never be returned by accident.
 */
final class UserPresenter
{
    /** @param array<string, mixed> $user row from UserRepository::find()/all() */
    public static function present(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'status' => $user['status'],
            'totpEnabled' => !empty($user['totp_enabled_at']),
            'anonymised' => !empty($user['anonymised_at']),
            'role' => ['slug' => $user['role_slug'], 'name' => $user['role_name']],
            'lastLoginAt' => self::iso($user['last_login_at']),
            'createdAt' => self::iso($user['created_at']),
            'updatedAt' => self::iso($user['updated_at']),
        ];
    }

    /** Signed-in user + what they may do (drives the UI; the server still checks every request). */
    public static function presentSelf(array $user): array
    {
        return self::present($user) + ['permissions' => Authorization::permissionsFor($user['role_slug'])];
    }

    private static function iso(?string $utcDateTime): ?string
    {
        return $utcDateTime === null ? null : str_replace(' ', 'T', $utcDateTime) . 'Z';
    }
}
