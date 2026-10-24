<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Authorization;
use PHPUnit\Framework\TestCase;

final class AuthorizationTest extends TestCase
{
    private static function user(string $role, string $status = 'active'): array
    {
        return ['id' => 1, 'role_slug' => $role, 'status' => $status];
    }

    public function testSuperAdminHasEveryDefinedPermission(): void
    {
        self::assertSame(
            ['users.view', 'users.create', 'users.update', 'users.change_status', 'users.assign_role'],
            Authorization::permissionsFor('super_admin'),
        );
    }

    public function testSalesRolesHaveNoAdministrativePermissions(): void
    {
        self::assertFalse(Authorization::can(self::user('sales_manager'), 'users.view'));
        self::assertFalse(Authorization::can(self::user('sales_user'), 'users.create'));
    }

    public function testDisabledUsersHaveNoPermissions(): void
    {
        self::assertFalse(Authorization::can(self::user('super_admin', 'disabled'), 'users.view'));
    }

    public function testUnknownRolesAndPermissionsAreDenied(): void
    {
        self::assertFalse(Authorization::can(self::user('emperor'), 'users.view'));
        self::assertFalse(Authorization::can(self::user('super_admin'), 'products.delete'));
    }

    public function testRoleAssignmentFollowsRank(): void
    {
        $admin = self::user('admin');

        self::assertTrue(Authorization::canAssignRole($admin, 'sales_manager'));
        self::assertFalse(Authorization::canAssignRole($admin, 'admin'));
        self::assertFalse(Authorization::canAssignRole($admin, 'super_admin'));
        self::assertTrue(Authorization::canAssignRole(self::user('super_admin'), 'super_admin'));
        self::assertFalse(Authorization::canAssignRole(self::user('super_admin'), 'emperor'));
    }

    public function testManagingUsersFollowsRank(): void
    {
        self::assertTrue(Authorization::canManageUser(self::user('admin'), self::user('sales_user')));
        self::assertFalse(Authorization::canManageUser(self::user('admin'), self::user('admin')));
        self::assertFalse(Authorization::canManageUser(self::user('admin'), self::user('super_admin')));
        self::assertTrue(Authorization::canManageUser(self::user('super_admin'), self::user('super_admin')));
    }
}
