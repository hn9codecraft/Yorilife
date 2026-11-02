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
        $all = array_keys((require dirname(__DIR__, 2) . '/config/permissions.php')['permissions']);
        self::assertSame($all, Authorization::permissionsFor('super_admin'));
        self::assertContains('users.assign_role', $all);
        self::assertContains('sales.update', $all);
    }

    public function testSalesRolesHaveNoAdministrativePermissions(): void
    {
        self::assertFalse(Authorization::can(self::user('sales_manager'), 'users.view'));
        self::assertFalse(Authorization::can(self::user('sales_user'), 'users.create'));
    }

    public function testAdminHasEveryBusinessPermission(): void
    {
        foreach (Authorization::permissionsFor('super_admin') as $permission) {
            self::assertTrue(Authorization::can(self::user('admin'), $permission), $permission);
        }
    }

    public function testSalesRolesMayOnlyViewBusinessScreens(): void
    {
        $expected = ['sales_office.view', 'catalogue.view', 'products.view', 'master_sheet.view', 'sales.view'];
        foreach (['sales_manager', 'sales_user'] as $role) {
            self::assertSame($expected, Authorization::permissionsFor($role), $role);
            foreach (['sales_office.manage', 'catalogue.manage', 'catalogue.restore', 'products.manage', 'products.delete', 'products.restore', 'financial_years.manage', 'master_sheet.update', 'sales.create', 'sales.update'] as $permission) {
                self::assertFalse(Authorization::can(self::user($role), $permission), "{$role} {$permission}");
            }
        }
    }

    public function testDisabledUsersHaveNoPermissions(): void
    {
        self::assertFalse(Authorization::can(self::user('super_admin', 'disabled'), 'users.view'));
    }

    public function testUnknownRolesAndPermissionsAreDenied(): void
    {
        self::assertFalse(Authorization::can(self::user('emperor'), 'users.view'));
        self::assertFalse(Authorization::can(self::user('super_admin'), 'products.purge'));
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
