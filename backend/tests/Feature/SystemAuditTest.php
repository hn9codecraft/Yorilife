<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

final class SystemAuditTest extends FeatureTestCase
{
    public function testLoginAndLogoutWriteAuditRows(): void
    {
        $this->createUser('audit-login@example.test', 'admin');
        $client = new ApiClient();
        $login = $client->login('audit-login@example.test', self::PASSWORD);
        self::assertSame(200, $login['status'], $login['raw']);

        $pdo = Database::connection();
        $ok = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE module = 'auth' AND action = 'login' AND result = 'ok'")->fetchColumn();
        self::assertGreaterThan(0, (int) $ok);

        $logout = $client->request('POST', '/api/auth/logout');
        self::assertSame(200, $logout['status'], $logout['raw']);
        $out = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE module = 'auth' AND action = 'logout'")->fetchColumn();
        self::assertGreaterThan(0, (int) $out);
    }

    public function testFailedLoginIsAuditedWithoutLeakingSecrets(): void
    {
        $this->createUser('audit-fail@example.test', 'admin');
        $response = (new ApiClient())->login('audit-fail@example.test', 'wrong-password-xx');
        self::assertSame(401, $response['status']);

        $row = Database::connection()->query(
            "SELECT details FROM audit_logs WHERE module = 'auth' AND action = 'login' AND result = 'failed' ORDER BY id DESC LIMIT 1"
        )->fetch();
        self::assertNotFalse($row);
        self::assertStringNotContainsString('wrong-password', (string) $row['details']);
        self::assertStringNotContainsString(self::PASSWORD, (string) $row['details']);
    }

    public function testUserCreateWritesAuditAndChangeHistory(): void
    {
        $admin = $this->signedInAs('admin');
        $response = $admin->request('POST', '/api/users', [
            'name' => 'New Person',
            'email' => 'new.person@example.test',
            'role' => 'sales_user',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(201, $response['status'], $response['raw']);
        $userId = (int) $response['body']['data']['user']['id'];

        $pdo = Database::connection();
        $audit = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE module = 'users' AND action = 'create' AND target_id = {$userId}")->fetchColumn();
        self::assertSame(1, $audit);
        $history = (int) $pdo->query("SELECT COUNT(*) FROM entity_changes WHERE entity_type = 'user' AND entity_id = {$userId}")->fetchColumn();
        self::assertGreaterThan(0, $history);
    }

    public function testAuditLogsAndChangeHistoryRequirePermission(): void
    {
        $sales = $this->signedInAs('sales_manager');
        self::assertError($sales->request('GET', '/api/audit-logs'), 403, 'forbidden');
        self::assertError($sales->request('GET', '/api/change-history'), 403, 'forbidden');
        self::assertError($sales->request('GET', '/api/settings'), 403, 'forbidden');
    }

    public function testAdminCanListAuditLogsChangeHistoryAndSettings(): void
    {
        $admin = $this->signedInAs('admin');
        // Seed one user change so change history is non-empty from entity_changes.
        $admin->request('POST', '/api/users', [
            'name' => 'Listed User',
            'email' => 'listed@example.test',
            'role' => 'sales_user',
            'password' => self::PASSWORD,
        ]);

        $audit = $admin->request('GET', '/api/audit-logs');
        self::assertSame(200, $audit['status'], $audit['raw']);
        self::assertArrayHasKey('items', $audit['body']['data']);
        self::assertNotEmpty($audit['body']['data']['items']);

        $history = $admin->request('GET', '/api/change-history');
        self::assertSame(200, $history['status'], $history['raw']);
        self::assertArrayHasKey('items', $history['body']['data']);

        $settings = $admin->request('GET', '/api/settings');
        self::assertSame(200, $settings['status'], $settings['raw']);
        self::assertTrue($settings['body']['data']['readOnly']);
        self::assertNotEmpty($settings['body']['data']['groups']);
    }

    public function testDeniedPermissionIsAudited(): void
    {
        $sales = $this->signedInAs('sales_user');
        self::assertError($sales->request('GET', '/api/users'), 403, 'forbidden');
        $count = (int) Database::connection()->query(
            "SELECT COUNT(*) FROM audit_logs WHERE result = 'denied' AND action = 'permission_denied'"
        )->fetchColumn();
        self::assertGreaterThan(0, $count);
    }

    public function testProductHistoryIsBridgedIntoChangeHistory(): void
    {
        $admin = $this->signedInAs('admin');
        $create = $admin->request('POST', '/api/products', [
            'name' => 'Audit Tablet',
            'code' => 'AUD-TAB-1',
            'scheme' => '',
        ]);
        self::assertSame(201, $create['status'], $create['raw']);

        $history = $admin->request('GET', '/api/change-history?module=products');
        self::assertSame(200, $history['status'], $history['raw']);
        $labels = array_column($history['body']['data']['items'], 'entityLabel');
        self::assertContains('Audit Tablet', $labels);
    }
}
