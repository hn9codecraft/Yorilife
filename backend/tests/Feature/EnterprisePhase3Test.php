<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use App\Services\AuditChain;
use App\Services\Totp;
use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

/** Phase 3: authenticator app, device tokens, retention, privacy, and revocation. */
final class EnterprisePhase3Test extends FeatureTestCase
{
    public function testAuthenticatorSetupLoginRecoveryAndAudit(): void
    {
        $user = $this->signedInAs('sales_user', 'totp-user@example.test');
        $setup = $user->request('POST', '/api/auth/2fa/setup', []);
        self::assertSame(200, $setup['status'], $setup['raw']);
        $secret = $setup['body']['data']['secret'];
        self::assertNotSame('', $secret);
        self::assertStringContainsString('otpauth://totp/', $setup['body']['data']['otpauthUri']);

        $confirmed = $user->request('POST', '/api/auth/2fa/confirm', ['code' => Totp::code($secret)]);
        self::assertSame(200, $confirmed['status'], $confirmed['raw']);
        $codes = $confirmed['body']['data']['recoveryCodes'];
        self::assertCount(8, $codes);
        $status = $user->request('GET', '/api/auth/2fa');
        self::assertTrue($status['body']['data']['enabled']);
        self::assertSame(8, $status['body']['data']['recoveryCodesRemaining']);
        self::assertStringNotContainsString($secret, $status['raw']);
        foreach ($codes as $code) {
            self::assertStringNotContainsString($code, $status['raw']);
        }

        $user->request('POST', '/api/auth/logout');
        $pending = new ApiClient();
        $login = $pending->login('totp-user@example.test', self::PASSWORD);
        self::assertSame(200, $login['status'], $login['raw']);
        self::assertSame('pending', $login['body']['data']['twoFactor']);
        self::assertError($pending->request('GET', '/api/headquarters'), 403, 'two_factor_required');

        $verified = $pending->request('POST', '/api/auth/2fa/verify', ['code' => Totp::code($secret)]);
        self::assertSame(200, $verified['status'], $verified['raw']);
        self::assertSame('ok', $verified['body']['data']['twoFactor']);
        self::assertSame(200, $pending->request('GET', '/api/headquarters')['status']);

        $pending->request('POST', '/api/auth/logout');
        $recovery = new ApiClient();
        self::assertSame('pending', $recovery->login('totp-user@example.test', self::PASSWORD)['body']['data']['twoFactor']);
        $used = $recovery->request('POST', '/api/auth/2fa/recover', ['code' => $codes[0]]);
        self::assertSame(200, $used['status'], $used['raw']);
        self::assertSame(200, $recovery->request('GET', '/api/auth/me')['status']);
        $recovery->request('POST', '/api/auth/logout');
        $again = new ApiClient();
        $again->login('totp-user@example.test', self::PASSWORD);
        self::assertError($again->request('POST', '/api/auth/2fa/recover', ['code' => $codes[0]]), 401, 'invalid_recovery_code');

        $again->request('POST', '/api/auth/2fa/verify', ['code' => Totp::code($secret)]);
        $disabled = $again->request('POST', '/api/auth/2fa/disable', [
            'password' => self::PASSWORD,
            'code' => Totp::code($secret),
        ]);
        self::assertSame(200, $disabled['status'], $disabled['raw']);
        self::assertFalse($disabled['body']['data']['enabled']);
        self::assertStringNotContainsString($secret, $disabled['raw']);

        $actions = Database::connection()->query(
            "SELECT action FROM audit_logs WHERE action IN ('totp_setup', 'totp_enable', 'totp_verify', 'totp_recover')"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['totp_setup', 'totp_enable', 'totp_verify', 'totp_recover'] as $action) {
            self::assertContains($action, $actions, $action);
        }
        $blob = (string) Database::connection()->query('SELECT GROUP_CONCAT(details) FROM audit_logs')->fetchColumn();
        self::assertStringNotContainsString($secret, $blob);
        self::assertStringNotContainsString($codes[0], $blob);
    }

    public function testSuperAdminCanRequireAuthenticatorForARole(): void
    {
        $super = $this->signedInAs('super_admin');
        $saved = $super->request('PATCH', '/api/settings', ['values' => ['security.totp_required_sales_user' => true]]);
        self::assertSame(200, $saved['status'], $saved['raw']);
        self::assertError(
            $this->signedInAs('admin')->request('PATCH', '/api/settings', ['values' => ['security.totp_required_sales_user' => false]]),
            403,
            'settings_not_editable',
        );

        $this->createUser('required-totp@example.test', 'sales_user', 'active', 'Required User');
        $client = new ApiClient();
        $login = $client->login('required-totp@example.test', self::PASSWORD);
        self::assertSame(200, $login['status'], $login['raw']);
        self::assertSame('enroll', $login['body']['data']['twoFactor']);
        self::assertError($client->request('GET', '/api/headquarters'), 403, 'two_factor_enrollment_required');

        $setup = $client->request('POST', '/api/auth/2fa/setup', []);
        $confirmed = $client->request('POST', '/api/auth/2fa/confirm', [
            'code' => Totp::code($setup['body']['data']['secret']),
        ]);
        self::assertSame(200, $confirmed['status'], $confirmed['raw']);
        self::assertSame(200, $client->request('GET', '/api/headquarters')['status']);
    }

    public function testDeviceTokensAreCreatedListedRevokedAndAudited(): void
    {
        $user = $this->signedInAs('sales_user', 'device-user@example.test');
        $created = $user->request('POST', '/api/auth/tokens', ['name' => 'Riya phone', 'expiresInDays' => 30]);
        self::assertSame(201, $created['status'], $created['raw']);
        $plain = $created['body']['data']['token'];
        self::assertStringStartsWith('ylt_', $plain);
        $listed = $user->request('GET', '/api/auth/tokens');
        self::assertSame(200, $listed['status'], $listed['raw']);
        self::assertStringNotContainsString($plain, $listed['raw']);
        self::assertSame('Riya phone', $listed['body']['data']['tokens'][0]['name']);
        self::assertNull($listed['body']['data']['tokens'][0]['lastUsedAt']);
        self::assertNotNull($listed['body']['data']['tokens'][0]['expiresAt']);

        $mobile = new ApiClient();
        $me = $mobile->request('GET', '/api/auth/me', null, ['Authorization' => 'Bearer ' . $plain]);
        self::assertSame(200, $me['status'], $me['raw']);
        self::assertSame('device-user@example.test', $me['body']['data']['user']['email']);
        $afterUse = $user->request('GET', '/api/auth/tokens');
        self::assertNotNull($afterUse['body']['data']['tokens'][0]['lastUsedAt']);

        $second = $mobile->request('POST', '/api/auth/tokens', ['name' => 'Backup phone'], ['Authorization' => 'Bearer ' . $plain]);
        self::assertSame(201, $second['status'], $second['raw']);

        $id = (int) $created['body']['data']['apiToken']['id'];
        $revoked = $user->request('POST', "/api/auth/tokens/{$id}/revoke", []);
        self::assertSame(200, $revoked['status'], $revoked['raw']);
        self::assertNotNull($revoked['body']['data']['apiToken']['revokedAt']);
        self::assertError(
            (new ApiClient())->request('GET', '/api/auth/me', null, ['Authorization' => 'Bearer ' . $plain]),
            401,
            'invalid_token',
        );

        $actions = Database::connection()->query(
            "SELECT action, result FROM audit_logs WHERE action IN ('token_create', 'token_use', 'token_revoke')"
        )->fetchAll();
        $names = array_column($actions, 'action');
        self::assertContains('token_create', $names);
        self::assertContains('token_use', $names);
        self::assertContains('token_revoke', $names);
        self::assertStringNotContainsString($plain, (string) Database::connection()->query('SELECT GROUP_CONCAT(details) FROM audit_logs')->fetchColumn());
    }

    public function testPasswordResetAndLockRevokeSessionsAndTokens(): void
    {
        $admin = $this->signedInAs('admin');
        $created = $admin->request('POST', '/api/users', [
            'name' => 'Session Person',
            'email' => 'session.person@example.test',
            'role' => 'sales_user',
            'password' => self::PASSWORD,
        ]);
        $id = (int) $created['body']['data']['user']['id'];
        $person = new ApiClient();
        self::assertSame(200, $person->login('session.person@example.test', self::PASSWORD)['status']);
        $token = $person->request('POST', '/api/auth/tokens', ['name' => 'Field phone'])['body']['data']['token'];

        $newPassword = 'Replacement-Pass-99';
        self::assertSame(200, $admin->request('POST', "/api/users/{$id}/password", ['password' => $newPassword])['status']);
        self::assertError($person->request('GET', '/api/auth/me'), 401, 'session_expired');
        self::assertError(
            (new ApiClient())->request('GET', '/api/auth/me', null, ['Authorization' => 'Bearer ' . $token]),
            401,
            'invalid_token',
        );
        self::assertSame(401, (new ApiClient())->login('session.person@example.test', self::PASSWORD)['status']);

        $again = new ApiClient();
        self::assertSame(200, $again->login('session.person@example.test', $newPassword)['status']);
        $second = $again->request('POST', '/api/auth/tokens', ['name' => 'Second phone'])['body']['data']['token'];
        self::assertSame(200, $admin->request('PATCH', "/api/users/{$id}", ['status' => 'locked'])['status']);
        self::assertError($again->request('GET', '/api/auth/me'), 401, 'session_expired');
        self::assertError(
            (new ApiClient())->request('GET', '/api/auth/me', null, ['Authorization' => 'Bearer ' . $second]),
            401,
            'invalid_token',
        );
        self::assertError((new ApiClient())->login('session.person@example.test', $newPassword), 403, 'account_locked');
    }

    public function testRetentionArchivesEveryDataTypeAndDeletesNothing(): void
    {
        $admin = $this->signedInAs('admin');
        $pdo = Database::connection();
        $userId = (int) $admin->request('GET', '/api/auth/me')['body']['data']['user']['id'];
        $pdo->prepare(
            "INSERT INTO audit_logs (occurred_at, actor_label, module, action, result) VALUES ('2020-01-01 00:00:00', 'system', 'system', 'old_retention', 'ok')"
        )->execute();
        $pdo->prepare(
            "INSERT INTO login_attempts (identifier_hash, ip_address, succeeded, attempted_at) VALUES (?, '127.0.0.1', 0, '2020-01-01 00:00:00')"
        )->execute([str_repeat('ab', 32)]);
        $pdo->prepare(
            "INSERT INTO privacy_requests (subject_type, subject_id, kind, status, requested_by, requested_at)
             VALUES ('user', ?, 'export', 'open', ?, '2020-01-01 00:00:00')"
        )->execute([$userId, $userId]);
        $pdo->prepare(
            "INSERT INTO imports (entity_type, original_filename, stored_filename, file_size, file_sha256, status, uploaded_at, created_at, updated_at)
             VALUES ('headquarters', 'old.xlsx', 'old.xlsx', 10, ?, 'previewed', '2020-01-01 00:00:00', '2020-01-01 00:00:00', '2020-01-01 00:00:00')"
        )->execute([str_repeat('c', 64)]);
        $importId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO import_audit_logs (import_id, action, created_at) VALUES (?, 'upload', '2020-01-01 00:00:00')"
        )->execute([$importId]);
        AuditChain::sealUnsealed();

        $before = [
            'audit' => (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(),
            'attempts' => (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn(),
            'privacy' => (int) $pdo->query('SELECT COUNT(*) FROM privacy_requests')->fetchColumn(),
            'importAudit' => (int) $pdo->query('SELECT COUNT(*) FROM import_audit_logs')->fetchColumn(),
        ];
        $applied = $admin->request('POST', '/api/retention/apply', []);
        self::assertSame(200, $applied['status'], $applied['raw']);
        self::assertSame(0, $applied['body']['data']['deleted']);
        self::assertGreaterThanOrEqual(1, $applied['body']['data']['archived']['auditLogs']);
        self::assertGreaterThanOrEqual(1, $applied['body']['data']['archived']['loginAttempts']);
        self::assertGreaterThanOrEqual(1, $applied['body']['data']['archived']['privacyRecords']);
        self::assertGreaterThanOrEqual(1, $applied['body']['data']['archived']['importAudit']);
        self::assertSame($before['audit'] + 1, (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
        self::assertSame($before['attempts'], (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
        self::assertSame($before['privacy'], (int) $pdo->query('SELECT COUNT(*) FROM privacy_requests')->fetchColumn());
        self::assertSame($before['importAudit'], (int) $pdo->query('SELECT COUNT(*) FROM import_audit_logs')->fetchColumn());
        self::assertNotNull($pdo->query("SELECT archived_at FROM audit_logs WHERE action = 'old_retention'")->fetchColumn());
        self::assertNotNull($pdo->query("SELECT archived_at FROM login_attempts WHERE attempted_at = '2020-01-01 00:00:00'")->fetchColumn());
        self::assertNotNull($pdo->query("SELECT archived_at FROM import_audit_logs WHERE import_id = {$importId}")->fetchColumn());
        self::assertTrue($admin->request('GET', '/api/audit-logs/verify')['body']['data']['intact']);

        $sales = $this->signedInAs('sales_user', 'retention-sales@example.test');
        self::assertError($sales->request('POST', '/api/retention/apply', []), 403, 'forbidden');
    }

    public function testDeletingTheNewestAuditRowsBreaksTheChainHead(): void
    {
        $admin = $this->signedInAs('admin');
        $intact = $admin->request('GET', '/api/audit-logs/verify');
        self::assertTrue($intact['body']['data']['intact']);
        $pdo = Database::connection();
        $headBefore = (int) $pdo->query('SELECT last_log_id FROM audit_chain_head WHERE id = 1')->fetchColumn();
        $before = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $pdo->exec('DELETE FROM audit_logs WHERE id = (SELECT id FROM (SELECT MAX(id) AS id FROM audit_logs) AS newest)');

        $broken = $admin->request('GET', '/api/audit-logs/verify');
        self::assertSame(200, $broken['status'], $broken['raw']);
        self::assertFalse($broken['body']['data']['intact']);
        self::assertSame('tail_truncated', $broken['body']['data']['reason']);
        self::assertSame($headBefore, $broken['body']['data']['brokenAt']);
        self::assertSame($before - 1, (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
        self::assertSame($headBefore, (int) $pdo->query('SELECT last_log_id FROM audit_chain_head WHERE id = 1')->fetchColumn());
    }

    public function testPrivacyConsentExportCorrectionAndAnonymize(): void
    {
        $admin = $this->signedInAs('admin');
        $created = $admin->request('POST', '/api/users', [
            'name' => 'Privacy Person',
            'email' => 'privacy.person@example.test',
            'role' => 'sales_user',
            'password' => self::PASSWORD,
        ]);
        $userId = (int) $created['body']['data']['user']['id'];
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO employees (name, email, phone, status) VALUES ('Meera Nair', 'meera.nair@example.test', '9876543210', 'active')")->execute();
        $employeeId = (int) $pdo->lastInsertId();

        $consent = $admin->request('POST', '/api/privacy/consents', [
            'subjectType' => 'employee',
            'subjectId' => $employeeId,
            'purpose' => 'communications',
            'granted' => true,
            'note' => 'Asked in person',
        ]);
        self::assertSame(201, $consent['status'], $consent['raw']);
        self::assertTrue($consent['body']['data']['consent']['granted']);
        self::assertSame(200, $admin->request('GET', '/api/privacy/consents?subjectType=employee&subjectId=' . $employeeId)['status']);

        $correction = $admin->request('POST', '/api/privacy/requests', [
            'subjectType' => 'user',
            'subjectId' => $userId,
            'kind' => 'correction',
            'details' => 'Please correct the spelling of the name.',
        ]);
        self::assertSame(201, $correction['status'], $correction['raw']);
        $correctionId = (int) $correction['body']['data']['request']['id'];
        $updated = $admin->request('PATCH', "/api/privacy/requests/{$correctionId}", [
            'status' => 'completed',
            'resolution' => 'Name checked with the person and left as entered.',
        ]);
        self::assertSame(200, $updated['status'], $updated['raw']);
        self::assertSame('completed', $updated['body']['data']['request']['status']);

        $exportRequest = $admin->request('POST', '/api/privacy/requests', [
            'subjectType' => 'user',
            'subjectId' => $userId,
            'kind' => 'export',
        ]);
        $exportId = (int) $exportRequest['body']['data']['request']['id'];
        $export = $admin->request('POST', "/api/privacy/requests/{$exportId}/export", []);
        self::assertSame(200, $export['status'], $export['raw']);
        self::assertStringContainsString('privacy.person@example.test', $export['raw']);
        self::assertStringNotContainsString('password_hash', $export['raw']);
        self::assertSame('completed', $export['body']['data']['request']['status']);

        $own = $admin->request('GET', '/api/privacy/me');
        self::assertSame(200, $own['status'], $own['raw']);
        self::assertStringContainsString('admin@example.test', $own['raw']);

        $sales = $this->signedInAs('sales_user', 'privacy-sales@example.test');
        self::assertError($sales->request('POST', '/api/privacy/consents', [
            'subjectType' => 'user',
            'subjectId' => $userId,
            'purpose' => 'account',
            'granted' => true,
        ]), 403, 'forbidden');
        self::assertSame(200, $sales->request('GET', '/api/privacy/me')['status']);

        $self = $admin->request('POST', '/api/privacy/requests', [
            'subjectType' => 'user',
            'subjectId' => (int) $admin->request('GET', '/api/auth/me')['body']['data']['user']['id'],
            'kind' => 'anonymize',
            'details' => 'Please remove me.',
        ]);
        self::assertError($self, 403, 'cannot_anonymize_self');

        $person = new ApiClient();
        self::assertSame(200, $person->login('privacy.person@example.test', self::PASSWORD)['status']);
        $beforeUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $beforeEmployees = (int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn();
        $anonRequest = $admin->request('POST', '/api/privacy/requests', [
            'subjectType' => 'user',
            'subjectId' => $userId,
            'kind' => 'anonymize',
        ]);
        $anonId = (int) $anonRequest['body']['data']['request']['id'];
        $done = $admin->request('POST', "/api/privacy/requests/{$anonId}/anonymize", []);
        self::assertSame(200, $done['status'], $done['raw']);
        self::assertSame('completed', $done['body']['data']['request']['status']);
        self::assertSame($beforeUsers, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $masked = $pdo->query("SELECT name, email, status, password_hash FROM users WHERE id = {$userId}")->fetch();
        self::assertSame('Anonymised', $masked['name']);
        self::assertSame('disabled', $masked['status']);
        self::assertStringContainsString('@redacted.invalid', (string) $masked['email']);
        self::assertNotSame('', (string) $masked['password_hash']);
        self::assertStringNotContainsString('privacy.person@example.test', (string) $pdo->query(
            "SELECT GROUP_CONCAT(CONCAT(old_value, new_value)) FROM entity_changes WHERE entity_type = 'user' AND entity_id = {$userId}"
        )->fetchColumn());
        self::assertError($person->request('GET', '/api/auth/me'), 401, 'session_expired');

        $employeeRequest = $admin->request('POST', '/api/privacy/requests', [
            'subjectType' => 'employee',
            'subjectId' => $employeeId,
            'kind' => 'anonymize',
        ]);
        self::assertSame(200, $admin->request('POST', '/api/privacy/requests/' . $employeeRequest['body']['data']['request']['id'] . '/anonymize', [])['status']);
        self::assertSame($beforeEmployees, (int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn());
        $employee = $pdo->query("SELECT name, email, phone, deleted_at FROM employees WHERE id = {$employeeId}")->fetch();
        self::assertSame('Anonymised', $employee['name']);
        self::assertNull($employee['email']);
        self::assertNull($employee['phone']);
        self::assertNull($employee['deleted_at']);

        $actions = Database::connection()->query(
            "SELECT action FROM audit_logs WHERE module = 'privacy'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['privacy_consent', 'privacy_request', 'privacy_request_update', 'privacy_export', 'privacy_anonymize'] as $action) {
            self::assertContains($action, $actions, $action);
        }
    }
}
