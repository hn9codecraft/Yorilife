<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

final class UserManagementTest extends FeatureTestCase
{
    private function newUserPayload(string $role = 'sales_user', string $email = 'new@example.test'): array
    {
        return ['name' => 'New Person', 'email' => $email, 'role' => $role, 'password' => 'A-Long-Initial-Pass-42'];
    }

    public function testUnauthenticatedRequestsToProtectedEndpointsGet401(): void
    {
        $client = new ApiClient();
        $userId = $this->createUser('someone@example.test');

        foreach ([['GET', '/api/users'], ['GET', "/api/users/{$userId}"], ['GET', '/api/roles'], ['POST', '/api/users'], ['PATCH', "/api/users/{$userId}"], ['POST', '/api/auth/logout']] as [$method, $path]) {
            $response = $client->request($method, $path, $method === 'GET' ? null : []);
            self::assertSame(401, $response['status'], "{$method} {$path}: {$response['raw']}");
        }
    }

    public function testSignedInUserWithoutPermissionGets403(): void
    {
        $client = $this->signedInAs('sales_manager');
        $userId = $this->createUser('other@example.test');

        self::assertError($client->request('GET', '/api/users'), 403, 'forbidden');
        self::assertError($client->request('GET', "/api/users/{$userId}"), 403, 'forbidden');
        self::assertError($client->request('POST', '/api/users', $this->newUserPayload()), 403, 'forbidden');
        self::assertError($client->request('PATCH', "/api/users/{$userId}", ['status' => 'disabled']), 403, 'forbidden');
    }

    public function testAdminCanListViewCreateEditAndDisableUsers(): void
    {
        $admin = $this->signedInAs('admin');

        $created = $admin->request('POST', '/api/users', $this->newUserPayload('sales_manager', 'Ravi@Example.test'));
        self::assertSame(201, $created['status'], $created['raw']);
        $user = $created['body']['data']['user'];
        self::assertSame('ravi@example.test', $user['email'], 'email is normalised');
        self::assertSame('sales_manager', $user['role']['slug']);
        self::assertStringNotContainsString('password', strtolower(json_encode(array_keys($user))));

        $list = $admin->request('GET', '/api/users');
        self::assertSame(200, $list['status']);
        self::assertCount(2, $list['body']['data']['users']);

        $show = $admin->request('GET', "/api/users/{$user['id']}");
        self::assertSame('New Person', $show['body']['data']['user']['name']);

        $edited = $admin->request('PATCH', "/api/users/{$user['id']}", ['name' => 'Ravi Kumar', 'role' => 'sales_user']);
        self::assertSame(200, $edited['status'], $edited['raw']);
        self::assertSame('Ravi Kumar', $edited['body']['data']['user']['name']);
        self::assertSame('sales_user', $edited['body']['data']['user']['role']['slug']);

        $disabled = $admin->request('PATCH', "/api/users/{$user['id']}", ['status' => 'disabled']);
        self::assertSame('disabled', $disabled['body']['data']['user']['status']);
        self::assertError((new ApiClient())->login('ravi@example.test', 'A-Long-Initial-Pass-42'), 403, 'account_disabled');

        $reactivated = $admin->request('PATCH', "/api/users/{$user['id']}", ['status' => 'active']);
        self::assertSame('active', $reactivated['body']['data']['user']['status']);
        self::assertSame(200, (new ApiClient())->login('ravi@example.test', 'A-Long-Initial-Pass-42')['status']);
    }

    public function testCreatedUserPasswordIsStoredAsHashOnly(): void
    {
        $admin = $this->signedInAs('admin');
        $admin->request('POST', '/api/users', $this->newUserPayload());

        $hash = \App\Database\Database::connection()->query("SELECT password_hash FROM users WHERE email = 'new@example.test'")->fetchColumn();

        self::assertStringStartsWith('$2y$12$', $hash);
        self::assertStringNotContainsString('A-Long-Initial-Pass-42', $hash);
    }

    public function testCreateValidatesInput(): void
    {
        $admin = $this->signedInAs('admin');
        $this->createUser('taken@example.test');

        $response = $admin->request('POST', '/api/users', ['name' => '', 'email' => 'Taken@example.test', 'role' => 'emperor', 'password' => 'short', 'password_hash' => 'x']);

        self::assertError($response, 422, 'validation_failed');
        $fields = $response['body']['error']['details']['fields'];
        foreach (['name', 'role', 'password', 'password_hash'] as $field) {
            self::assertArrayHasKey($field, $fields);
        }
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $admin = $this->signedInAs('admin');
        $this->createUser('dup@example.test');

        $response = $admin->request('POST', '/api/users', $this->newUserPayload('sales_user', 'DUP@example.test'));

        self::assertError($response, 422, 'validation_failed');
        self::assertArrayHasKey('email', $response['body']['error']['details']['fields']);
    }

    public function testUsersCannotChangeTheirOwnRoleOrStatus(): void
    {
        $admin = $this->signedInAs('admin', 'self@example.test');
        $selfId = $admin->request('GET', '/api/auth/me')['body']['data']['user']['id'];

        self::assertError($admin->request('PATCH', "/api/users/{$selfId}", ['role' => 'super_admin']), 403, 'cannot_change_own_role');
        self::assertError($admin->request('PATCH', "/api/users/{$selfId}", ['status' => 'disabled']), 403, 'cannot_change_own_status');

        $me = $admin->request('GET', '/api/auth/me')['body']['data']['user'];
        self::assertSame('admin', $me['role']['slug']);
    }

    public function testAdminCannotEscalateOrManageHigherAccounts(): void
    {
        $admin = $this->signedInAs('admin');
        $otherAdmin = $this->createUser('other-admin@example.test', 'admin');
        $superAdmin = $this->createUser('owner@example.test', 'super_admin');
        $salesUser = $this->createUser('sales@example.test');

        self::assertError($admin->request('POST', '/api/users', $this->newUserPayload('super_admin', 'x1@example.test')), 403, 'role_not_assignable');
        self::assertError($admin->request('POST', '/api/users', $this->newUserPayload('admin', 'x2@example.test')), 403, 'role_not_assignable');
        self::assertError($admin->request('PATCH', "/api/users/{$salesUser}", ['role' => 'super_admin']), 403, 'role_not_assignable');
        self::assertError($admin->request('PATCH', "/api/users/{$superAdmin}", ['status' => 'disabled']), 403, 'cannot_manage_user');
        self::assertError($admin->request('PATCH', "/api/users/{$otherAdmin}", ['name' => 'Renamed']), 403, 'cannot_manage_user');
    }

    public function testRoleValuesFromTheClientAreNotTrusted(): void
    {
        $sales = $this->signedInAs('sales_user');

        // Claiming a role in headers/body does nothing; the server uses the session's user.
        $response = $sales->request('GET', '/api/users', null, ['X-User-Role' => 'super_admin']);

        self::assertError($response, 403, 'forbidden');
    }

    public function testSuperAdminCanManageAdministrators(): void
    {
        $owner = $this->signedInAs('super_admin');
        $admin = $this->createUser('admin@example.test', 'admin');

        $promoted = $owner->request('POST', '/api/users', $this->newUserPayload('admin', 'second-admin@example.test'));
        $disabled = $owner->request('PATCH', "/api/users/{$admin}", ['status' => 'disabled']);

        self::assertSame(201, $promoted['status'], $promoted['raw']);
        self::assertSame('disabled', $disabled['body']['data']['user']['status']);
    }

    public function testRolesEndpointMarksAssignableRolesForTheActor(): void
    {
        $roles = $this->signedInAs('admin')->request('GET', '/api/roles')['body']['data']['roles'];
        $assignable = array_column($roles, 'assignable', 'slug');

        self::assertSame(['super_admin' => false, 'admin' => false, 'sales_manager' => true, 'sales_user' => true], $assignable);
    }

    public function testMutationsWithoutCsrfTokenAreRejected(): void
    {
        $admin = $this->signedInAs('admin');

        $response = $admin->request('POST', '/api/users', $this->newUserPayload(), withCsrf: false);

        self::assertError($response, 403, 'csrf_token_invalid');
    }

    public function testUnknownUserIdReturns404(): void
    {
        $admin = $this->signedInAs('admin');

        self::assertSame(404, $admin->request('GET', '/api/users/999999')['status']);
        self::assertSame(404, $admin->request('GET', '/api/users/abc')['status']);
    }
}
