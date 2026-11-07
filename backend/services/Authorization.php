<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\HttpException;

/**
 * Central authorization rules, driven by config/permissions.php.
 * Always evaluated on the server against the user loaded from the database —
 * role values sent by the browser are never trusted.
 */
final class Authorization
{
    /** @var array{permissions: array<string, string>, roles: array<string, array{rank: int, permissions: list<string>}>}|null */
    private static ?array $config = null;

    private static function config(): array
    {
        return self::$config ??= require dirname(__DIR__) . '/config/permissions.php';
    }

    /** @return list<string> every permission the role grants ("*" expanded) */
    public static function permissionsFor(string $roleSlug): array
    {
        $granted = self::config()['roles'][$roleSlug]['permissions'] ?? [];

        return in_array('*', $granted, true) ? array_keys(self::config()['permissions']) : $granted;
    }

    public static function rank(string $roleSlug): int
    {
        return self::config()['roles'][$roleSlug]['rank'] ?? 0;
    }

    public static function isKnownRole(string $roleSlug): bool
    {
        return isset(self::config()['roles'][$roleSlug]);
    }

    /** @param array<string, mixed> $user row from UserRepository::find() */
    public static function can(array $user, string $permission): bool
    {
        return $user['status'] === 'active' && in_array($permission, self::permissionsFor($user['role_slug']), true);
    }

    public static function authorize(array $user, string $permission): void
    {
        if (!self::can($user, $permission)) {
            RequestContext::setActor($user);
            AuditLogger::record([
                'module' => 'system',
                'action' => 'permission_denied',
                'result' => 'denied',
                'details' => ['permission' => $permission],
            ]);
            throw HttpException::forbidden();
        }
    }

    /** Super Admin manages everyone; others only accounts ranked below their own. */
    public static function canManageUser(array $actor, array $target): bool
    {
        return $actor['role_slug'] === 'super_admin' || self::rank($actor['role_slug']) > self::rank($target['role_slug']);
    }

    /** Super Admin may assign any role; others only roles ranked below their own. */
    public static function canAssignRole(array $actor, string $roleSlug): bool
    {
        return self::isKnownRole($roleSlug)
            && ($actor['role_slug'] === 'super_admin' || self::rank($actor['role_slug']) > self::rank($roleSlug));
    }
}
