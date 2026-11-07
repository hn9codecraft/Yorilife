<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use App\Services\AuditChain;
use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

/** Phase 2: hash chain, editable settings, auth policy, user admin, exports, archive. */
final class SystemHardeningTest extends FeatureTestCase
{
    public function testAuditChainIsIntactUntilARowIsTampered(): void
    {
        $admin = $this->signedInAs('admin');
        $ok = $admin->request('GET', '/api/audit-logs/verify');
        self::assertSame(200, $ok['status'], $ok['raw']);
        self::assertTrue($ok['body']['data']['intact']);
        self::assertGreaterThan(0, $ok['body']['data']['checked']);
        self::assertNull($ok['body']['data']['brokenAt']);

        Database::connection()->exec("UPDATE audit_logs SET action = 'tampered' WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM audit_logs) AS t)");
        $broken = $admin->request('GET', '/api/audit-logs/verify');
        self::assertSame(200, $broken['status'], $broken['raw']);
        self::assertFalse($broken['body']['data']['intact']);
        self::assertSame('hash_mismatch', $broken['body']['data']['reason']);
    }

    public function testSettingsGroupsPermissionsValidationAndHistory(): void
    {
        $admin = $this->signedInAs('admin');
        $view = $admin->request('GET', '/api/settings');
        self::assertSame(200, $view['status'], $view['raw']);
        $groups = array_column($view['body']['data']['groups'], null, 'id');
        self::assertTrue($groups['company']['editable']);
        self::assertTrue($groups['general']['editable']);
        self::assertTrue($groups['retention']['editable']);
        self::assertFalse($groups['security']['editable']);
        self::assertContains('settings.edit', $admin->request('GET', '/api/auth/me')['body']['data']['user']['permissions']);

        self::assertError($admin->request('PATCH', '/api/settings', ['values' => ['security.lockout_max_failures' => '3']]), 403, 'settings_not_editable');
        self::assertError($admin->request('PATCH', '/api/settings', ['values' => ['company.name' => '']]), 422, 'validation_failed');
        self::assertError($admin->request('PATCH', '/api/settings', ['values' => ['company.gstin' => 'not-a-gstin']]), 422, 'validation_failed');
        self::assertError($admin->request('PATCH', '/api/settings', ['values' => ['no.such' => 'x']]), 422, 'validation_failed');

        $saved = $admin->request('PATCH', '/api/settings', ['values' => [
            'company.name' => 'Yorilife Sciences',
            'company.gstin' => '27AAPFU0939F1ZV',
            'general.support_email' => 'Desk@Example.com',
        ]]);
        self::assertSame(200, $saved['status'], $saved['raw']);
        $company = array_column(array_column($saved['body']['data']['groups'], null, 'id')['company']['items'], 'value', 'key');
        self::assertSame('Yorilife Sciences', $company['company.name']);
        self::assertSame('27AAPFU0939F1ZV', $company['company.gstin']);
        $general = array_column(array_column($saved['body']['data']['groups'], null, 'id')['general']['items'], 'value', 'key');
        self::assertSame('desk@example.com', $general['general.support_email']);

        $history = $admin->request('GET', '/api/settings/history?key=company.name');
        self::assertSame(200, $history['status'], $history['raw']);
        $item = $history['body']['data']['items'][0];
        self::assertSame('Yorilife', $item['oldValue']);
        self::assertSame('Yorilife Sciences', $item['newValue']);
        self::assertSame('Admin', $item['changedByName']);

        $sales = $this->signedInAs('sales_user', 'settings-sales@example.test');
        self::assertError($sales->request('GET', '/api/settings'), 403, 'forbidden');
        self::assertError($sales->request('PATCH', '/api/settings', ['values' => ['company.name' => 'Nope']]), 403, 'forbidden');

        $super = $this->signedInAs('super_admin');
        $security = $super->request('PATCH', '/api/settings', ['values' => [
            'security.password_min_length' => 15,
            'security.password_require_symbol' => true,
            'security.lockout_max_failures' => 3,
        ]]);
        self::assertSame(200, $security['status'], $security['raw']);
        $short = $super->request('POST', '/api/users', [
            'name' => 'Short Password',
            'email' => 'short.pass@example.test',
            'role' => 'sales_user',
            'password' => 'Correct-Horse',
        ]);
        self::assertError($short, 422, 'validation_failed');
        self::assertArrayHasKey('password', $short['body']['error']['details']['fields']);
    }

    public function testSessionTimeoutAndLockoutFollowSecuritySettings(): void
    {
        $super = $this->signedInAs('super_admin');
        $saved = $super->request('PATCH', '/api/settings', ['values' => [
            'security.session_idle_minutes' => 60,
            'security.lockout_max_failures' => 3,
        ]]);
        self::assertSame(200, $saved['status'], $saved['raw']);

        $this->editSession($super, 'last_activity_at', time() - 31 * 60);
        self::assertSame(200, $super->request('GET', '/api/auth/me')['status'], '31 idle minutes is inside a 60 minute timeout');

        $this->createUser('locked-out@example.test');
        $client = new ApiClient();
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(401, $client->login('locked-out@example.test', 'wrong-password-' . $i)['status']);
        }
        $blocked = $client->login('locked-out@example.test', self::PASSWORD);
        self::assertError($blocked, 429, 'too_many_attempts');
    }

    public function testAdminResetsPasswordLocksAccountAndSeesLastLogin(): void
    {
        $admin = $this->signedInAs('admin');
        $created = $admin->request('POST', '/api/users', [
            'name' => 'Riya Shah',
            'email' => 'riya.shah@example.test',
            'role' => 'sales_user',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(201, $created['status'], $created['raw']);
        $id = (int) $created['body']['data']['user']['id'];
        self::assertArrayNotHasKey('password', $created['body']['data']['user']);

        $newPassword = 'Replacement-Pass-99';
        $reset = $admin->request('POST', "/api/users/{$id}/password", ['password' => $newPassword]);
        self::assertSame(200, $reset['status'], $reset['raw']);
        self::assertStringNotContainsString($newPassword, $reset['raw']);
        self::assertStringNotContainsString('password_hash', $reset['raw']);

        $pdo = Database::connection();
        foreach ($pdo->query('SELECT details FROM audit_logs') as $row) {
            self::assertStringNotContainsString($newPassword, (string) $row['details']);
        }
        foreach ($pdo->query('SELECT old_value, new_value FROM entity_changes') as $row) {
            self::assertStringNotContainsString($newPassword, (string) $row['old_value'] . (string) $row['new_value']);
        }
        $marker = $pdo->query("SELECT new_value FROM entity_changes WHERE entity_type = 'user' AND entity_id = {$id} AND field = 'Password'")->fetchColumn();
        self::assertSame('(reset)', $marker);

        self::assertSame(401, (new ApiClient())->login('riya.shah@example.test', self::PASSWORD)['status']);
        $riya = new ApiClient();
        self::assertSame(200, $riya->login('riya.shah@example.test', $newPassword)['status']);
        self::assertNotNull($riya->request('GET', '/api/auth/me')['body']['data']['user']['lastLoginAt']);

        self::assertError($admin->request('POST', '/api/users/' . $admin->request('GET', '/api/auth/me')['body']['data']['user']['id'] . '/password', ['password' => $newPassword]), 403, 'cannot_reset_own_password');

        $locked = $admin->request('PATCH', "/api/users/{$id}", ['status' => 'locked']);
        self::assertSame(200, $locked['status'], $locked['raw']);
        self::assertSame('locked', $locked['body']['data']['user']['status']);
        self::assertError((new ApiClient())->login('riya.shah@example.test', $newPassword), 403, 'account_locked');

        $unlocked = $admin->request('PATCH', "/api/users/{$id}", ['status' => 'active']);
        self::assertSame('active', $unlocked['body']['data']['user']['status']);
        self::assertSame(200, (new ApiClient())->login('riya.shah@example.test', $newPassword)['status']);

        $listed = $admin->request('GET', '/api/users');
        $row = array_column($listed['body']['data']['users'], null, 'id')[$id];
        self::assertNotNull($row['lastLoginAt']);
        self::assertArrayHasKey('minLength', $listed['body']['data']['passwordPolicy']);

        $sales = $this->signedInAs('sales_manager', 'reset-sales@example.test');
        self::assertError($sales->request('POST', "/api/users/{$id}/password", ['password' => $newPassword]), 403, 'forbidden');
    }

    public function testExportsMaskContactDetailsAndAreAudited(): void
    {
        $admin = $this->signedInAs('admin');
        $admin->request('POST', '/api/users', [
            'name' => 'Export Person',
            'email' => 'export.person@example.test',
            'role' => 'sales_user',
            'password' => self::PASSWORD,
        ]);
        $csv = $admin->request('GET', '/api/change-history/export');
        self::assertSame(200, $csv['status'], $csv['raw']);
        self::assertStringContainsString('text/csv', $csv['headers']['content-type']);
        self::assertStringNotContainsString('export.person@example.test', $csv['raw']);
        self::assertStringContainsString('e***@example.test', $csv['raw']);

        $auditCsv = $admin->request('GET', '/api/audit-logs/export');
        self::assertSame(200, $auditCsv['status'], $auditCsv['raw']);

        $recorded = (int) Database::connection()->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'export'"
        )->fetchColumn();
        self::assertGreaterThanOrEqual(2, $recorded);

        $sales = $this->signedInAs('sales_user', 'export-sales@example.test');
        $clientExport = $sales->request('POST', '/api/exports', [
            'kind' => 'sales_office_csv',
            'filename' => 'headquarters.csv',
            'rowCount' => 2,
        ]);
        self::assertSame(200, $clientExport['status'], $clientExport['raw']);
        self::assertError($sales->request('POST', '/api/exports', ['kind' => 'nope', 'filename' => 'x.csv', 'rowCount' => 1]), 422, 'validation_failed');
    }

    public function testArchiveMarksOldRowsAndLeavesThemInPlace(): void
    {
        $admin = $this->signedInAs('admin');
        $pdo = Database::connection();
        $pdo->prepare(
            "INSERT INTO audit_logs (occurred_at, actor_label, module, action, result) VALUES ('2020-01-01 00:00:00', 'system', 'system', 'old_note', 'ok')"
        )->execute();
        $pdo->prepare(
            "INSERT INTO entity_changes (entity_type, entity_id, entity_label, field, old_value, new_value, changed_at, source)
             VALUES ('user', 1, 'Old', 'Name', 'A', 'B', '2020-01-01 00:00:00', 'app')"
        )->execute();
        AuditChain::sealUnsealed();

        $before = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $archived = $admin->request('POST', '/api/audit-logs/archive', ['olderThanDays' => 30]);
        self::assertSame(200, $archived['status'], $archived['raw']);
        self::assertGreaterThanOrEqual(1, $archived['body']['data']['archived']);
        self::assertSame($before + 1, (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(), 'archive keeps every row and adds one record of the archive itself');
        self::assertNotNull($pdo->query("SELECT archived_at FROM audit_logs WHERE action = 'old_note'")->fetchColumn());

        $active = $admin->request('GET', '/api/audit-logs?q=old_note');
        self::assertSame([], array_values(array_filter(
            $active['body']['data']['items'],
            static fn (array $item): bool => ($item['action'] ?? '') === 'old_note'
        )));
        $only = $admin->request('GET', '/api/audit-logs?archived=only&q=old_note');
        self::assertNotEmpty($only['body']['data']['items']);
        self::assertTrue($admin->request('GET', '/api/audit-logs/verify')['body']['data']['intact']);

        $history = $admin->request('POST', '/api/change-history/archive', ['olderThanDays' => 30]);
        self::assertSame(200, $history['status'], $history['raw']);
        self::assertGreaterThanOrEqual(1, $history['body']['data']['archived']);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM entity_changes WHERE old_value = 'A' AND new_value = 'B'")->fetchColumn());
        self::assertNotNull($pdo->query("SELECT archived_at FROM entity_changes WHERE old_value = 'A'")->fetchColumn());

        $page = $admin->request('GET', '/api/audit-logs?limit=1&offset=0');
        self::assertSame(200, $page['status'], $page['raw']);
        self::assertLessThanOrEqual(1, count($page['body']['data']['items']));
        self::assertSame(1, $page['body']['data']['limit']);
        self::assertGreaterThanOrEqual(1, $page['body']['data']['total']);
    }
}
