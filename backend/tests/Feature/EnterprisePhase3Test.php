<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use App\Services\AuditChain;
use App\Services\Totp;
use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

/** Phase 3: authenticator, device keys, retention, DPDP, session revocation, chain head. */
final class EnterprisePhase3Test extends FeatureTestCase
{
    public function testAuthenticatorSetupLoginRecoveryAndAudit(): void
    {
        $user = $this->signedInAs('sales_user', 'auth-app@example.test');
        $setup = $user->request('POST', '/api/account/totp/setup', []);
        self::assertSame(200, $setup['status'], $setup['raw']);
        $secret = $setup['body']['data']['secret'];
        self::assertNotSame('', $secret);
        self::assertStringContainsString('otpauth://totp/', $setup['body']['data']['otpauthUri']);

        $confirm = $user->request('POST', '/api/account/totp/confirm', ['code' => Totp::code($secret)]);
        self::assertSame(200, $confirm['status'], $confirm['raw']);
        self::assertCount(8, $confirm['body']['data']['recoveryCodes']);
        self::assertStringNotContainsString($secret, json_encode($confirm['body']['data']['recoveryCodes']));
        $recovery = $confirm['body']['data']['recoveryCodes'][0];

        $status = $user->request('GET', '/api/account/security');
        self::assertTrue($status['body']['data']['totpEnabled']);
        self::assertSame(8, $status['body']['data']['recoveryCodesRemaining']);

        Database::connection()->prepare('UPDATE users SET totp_last_step = 0 WHERE email = ?')->execute(['auth-app@example.test']);
        self::assertSame(200, $user->request('POST', '/api/auth/logout')['status']);

        $again = new ApiClient();
        $password = $again->login('auth-app@example.test', self::PASSWORD);
        self::assertSame(200, $password['status'], $password['raw']);
        self::assertTrue($password['body']['data']['twoFactorRequired']);
        self::assertArrayNotHasKey('csrfToken', $password['body']['data']);
        self::assertArrayNotHasKey('user', $password['body']['data']);
        self::assertSame(401, $again->request('GET', '/api/auth/me')['status']);

        $wrong = $again->request('POST', '/api/auth/totp', [
            'challengeToken' => $password['body']['data']['challengeToken'],
            'code' => '000000',
        ]);
        self::assertError($wrong, 401, 'totp_invalid');

        $ok = $again->request('POST', '/api/auth/totp', [
            'challengeToken' => $password['body']['data']['challengeToken'],
            'code' => Totp::code($secret),
        ]);
        self::assertSame(200, $ok['status'], $ok['raw']);
        self::assertSame('auth-app@example.test', $ok['body']['data']['user']['email']);
        self::assertTrue($ok['body']['data']['user']['totpEnabled']);
        self::assertSame(200, $again->request('GET', '/api/auth/me')['status']);

        Database::connection()->prepare('UPDATE users SET totp_last_step = 9999999999 WHERE email = ?')->execute(['auth-app@example.test']);
        $replay = new ApiClient();
        $second = $replay->login('auth-app@example.test', self::PASSWORD);
        $reused = $replay->request('POST', '/api/auth/totp', [
            'challengeToken' => $second['body']['data']['challengeToken'],
            'code' => Totp::code($secret),
        ]);
        self::assertError($reused, 401, 'totp_invalid');
        $withRecovery = $replay->request('POST', '/api/auth/totp', [
            'challengeToken' => $second['body']['data']['challengeToken'],
            'code' => $recovery,
        ]);
        self::assertSame(200, $withRecovery['status'], $withRecovery['raw']);
        self::assertSame(7, $replay->request('GET', '/api/account/security')['body']['data']['recoveryCodesRemaining']);

        $spent = new ApiClient();
        $third = $spent->login('auth-app@example.test', self::PASSWORD);
        $againRecovery = $spent->request('POST', '/api/auth/totp', [
            'challengeToken' => $third['body']['data']['challengeToken'],
            'code' => $recovery,
        ]);
        self::assertError($againRecovery, 401, 'totp_invalid');

        $actions = Database::connection()->query(
            "SELECT action FROM audit_logs WHERE module = 'auth' AND action IN ('totp_enabled', 'totp_used')"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('totp_enabled', $actions);
        self::assertContains('totp_used', $actions);
        $details = (string) Database::connection()->query(
            "SELECT COALESCE(GROUP_CONCAT(details), '') FROM audit_logs WHERE action IN ('totp_enabled', 'totp_used')"
        )->fetchColumn();
        self::assertStringNotContainsString($secret, $details);
        self::assertStringNotContainsString($recovery, $details);
    }

    public function testRequiredRoleMustEnrollBeforeASessionStarts(): void
    {
        $super = $this->signedInAs('super_admin');
        $saved = $super->request('PATCH', '/api/settings', ['values' => ['security.totp_required_sales_user' => true]]);
        self::assertSame(200, $saved['status'], $saved['raw']);
        self::assertError(
            $this->signedInAs('admin')->request('PATCH', '/api/settings', ['values' => ['security.totp_required_sales_user' => false]]),
            403,
            'settings_not_editable',
        );

        $this->createUser('must-enroll@example.test', 'sales_user', 'active', 'Must Enroll');
        $client = new ApiClient();
        $login = $client->login('must-enroll@example.test', self::PASSWORD);
        self::assertSame(200, $login['status'], $login['raw']);
        self::assertTrue($login['body']['data']['enrollmentRequired']);
        self::assertSame(401, $client->request('GET', '/api/auth/me')['status']);

        $token = $login['body']['data']['challengeToken'];
        $setup = $client->request('POST', '/api/auth/totp/enroll', ['challengeToken' => $token]);
        self::assertSame(200, $setup['status'], $setup['raw']);
        $confirm = $client->request('POST', '/api/auth/totp/enroll/confirm', [
            'challengeToken' => $token,
            'code' => Totp::code($setup['body']['data']['secret']),
        ]);
        self::assertSame(200, $confirm['status'], $confirm['raw']);
        self::assertCount(8, $confirm['body']['data']['recoveryCodes']);
        self::assertSame(200, $client->request('GET', '/api/auth/me')['status']);
        self::assertTrue($client->request('GET', '/api/auth/me')['body']['data']['user']['totpEnabled']);
    }

    public function testDeviceKeysListRevokeExpireAndAuthenticate(): void
    {
        $user = $this->signedInAs('sales_user', 'device-user@example.test');
        $created = $user->request('POST', '/api/account/tokens', ['name' => 'Field phone', 'expiresInDays' => 30]);
        self::assertSame(201, $created['status'], $created['raw']);
        $key = $created['body']['data']['key'];
        $device = $created['body']['data']['device'];
        self::assertStringStartsWith('ylt_', $key);
        self::assertStringStartsWith($device['prefix'], $key);
        self::assertArrayNotHasKey('key', $device);

        $list = $user->request('GET', '/api/account/tokens');
        self::assertSame($device['prefix'], $list['body']['data']['devices'][0]['prefix']);
        self::assertStringNotContainsString($key, $list['raw']);

        $mobile = new ApiClient();
        $me = $mobile->request('GET', '/api/auth/me', null, ['Authorization' => 'Bearer ' . $key]);
        self::assertSame(200, $me['status'], $me['raw']);
        self::assertSame('device-user@example.test', $me['body']['data']['user']['email']);
        self::assertNull($me['body']['data']['csrfToken']);
        $used = $user->request('GET', '/api/account/tokens');
        self::assertNotNull($used['body']['data']['devices'][0]['lastUsedAt']);

        $revoked = $user->request('POST', '/api/account/tokens/' . $device['id'] . '/revoke', []);
        self::assertSame(200, $revoked['status'], $revoked['raw']);
        self::assertNotNull($revoked['body']['data']['device']['revokedAt']);
        $blocked = $mobile->request('GET', '/api/auth/me', null, ['Authorization' => 'Bearer ' . $key]);
        self::assertError($blocked, 401, 'invalid_token');

        $another = $user->request('POST', '/api/account/tokens', ['name' => 'Spare phone', 'expiresInDays' => 7]);
        $spare = $another['body']['data']['key'];
        Database::connection()->prepare('UPDATE api_tokens SET expires_at = ? WHERE token_prefix = ?')
            ->execute(['2020-01-01 00:00:00', $another['body']['data']['device']['prefix']]);
        $expired = (new ApiClient())->request('GET', '/api/auth/me', null, ['Authorization' => 'Bearer ' . $spare]);
        self::assertError($expired, 401, 'token_expired');

        $actions = Database::connection()->query(
            "SELECT action FROM audit_logs WHERE action IN ('api_token_created', 'api_token_revoked')"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('api_token_created', $actions);
        self::assertContains('api_token_revoked', $actions);
        self::assertStringNotContainsString($key, (string) Database::connection()->query(
            "SELECT COALESCE(GROUP_CONCAT(details), '') FROM audit_logs WHERE action LIKE 'api_token_%'"
        )->fetchColumn());
    }

    public function testPasswordResetAndLockRevokeSessionsAndDeviceKeys(): void
    {
        $admin = $this->signedInAs('admin');
        $created = $admin->request('POST', '/api/users', [
            'name' => 'Locked Later',
            'email' => 'locked.later@example.test',
            'role' => 'sales_user',
            'password' => self::PASSWORD,
        ]);
        $id = (int) $created['body']['data']['user']['id'];

        $user = new ApiClient();
        self::assertSame(200, $user->login('locked.later@example.test', self::PASSWORD)['status']);
        $key = $user->request('POST', '/api/account/tokens', ['name' => 'Tablet'])['body']['data']['key'];
        self::assertSame(200, (new ApiClient())->request('GET', '/api/auth/me', null, ['Authorization' => 'Bearer ' . $key])['status']);

        $reset = $admin->request('POST', "/api/users/{$id}/password", ['password' => 'Replacement-Pass-99']);
        self::assertSame(200, $reset['status'], $reset['raw']);
        self::assertSame(401, $user->request('GET', '/api/auth/me')['status']);
        self::assertError(
            (new ApiClient())->request('GET', '/api/auth/me', null, ['Authorization' => 'Bearer ' . $key]),
            401,
            'invalid_token',
        );

        $fresh = new ApiClient();
        self::assertSame(200, $fresh->login('locked.later@example.test', 'Replacement-Pass-99')['status']);
        $second = $fresh->request('POST', '/api/account/tokens', ['name' => 'Other tablet'])['body']['data']['key'];
        $locked = $admin->request('PATCH', "/api/users/{$id}", ['status' => 'locked']);
        self::assertSame(200, $locked['status'], $locked['raw']);
        self::assertSame(401, $fresh->request('GET', '/api/auth/me')['status']);
        self::assertError(
            (new ApiClient())->request('GET', '/api/auth/me', null, ['Authorization' => 'Bearer ' . $second]),
            401,
            'invalid_token',
        );
    }

    public function testDeletingTheNewestAuditRowBreaksTheChainHead(): void
    {
        $admin = $this->signedInAs('admin');
        $pdo = Database::connection();
        $before = $admin->request('GET', '/api/audit-logs/verify');
        self::assertTrue($before['body']['data']['intact'], $before['raw']);

        $tip = (int) $pdo->query('SELECT last_log_id FROM audit_chain_head WHERE id = 1')->fetchColumn();
        self::assertGreaterThan(0, $tip);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $pdo->prepare('DELETE FROM audit_logs WHERE id = ?')->execute([$tip]);

        $after = $admin->request('GET', '/api/audit-logs/verify');
        self::assertSame(200, $after['status'], $after['raw']);
        self::assertFalse($after['body']['data']['intact']);
        self::assertSame('tail_truncated', $after['body']['data']['reason']);
        self::assertSame($tip, $after['body']['data']['brokenAt']);
        self::assertSame($count - 1, (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
        self::assertSame($tip, (int) $pdo->query('SELECT last_log_id FROM audit_chain_head WHERE id = 1')->fetchColumn());
    }

    public function testRetentionArchivesConfiguredTypesAndDeletesNothing(): void
    {
        $admin = $this->signedInAs('admin');
        self::assertFalse($admin->request('GET', '/api/settings')['body']['data']['canApplyRetention']);
        self::assertError($admin->request('POST', '/api/retention/apply', []), 403, 'settings_not_editable');
        self::assertError(
            $admin->request('PATCH', '/api/settings', ['values' => ['retention.login_attempt_days' => 90]]),
            403,
            'settings_not_editable',
        );

        $super = $this->signedInAs('super_admin');
        $pdo = Database::connection();
        $pdo->prepare(
            "INSERT INTO audit_logs (occurred_at, actor_label, module, action, result) VALUES ('2020-01-01 00:00:00', 'system', 'system', 'old_retention', 'ok')"
        )->execute();
        $pdo->prepare(
            "INSERT INTO login_attempts (identifier_hash, ip_address, succeeded, attempted_at) VALUES (?, '127.0.0.1', 0, '2020-01-01 00:00:00')"
        )->execute([str_repeat('a', 64)]);
        $pdo->prepare(
            "INSERT INTO imports (entity_type, original_filename, stored_filename, file_size, file_sha256) VALUES ('unified', 'old.xlsx', 'old.xlsx', 10, ?)"
        )->execute([str_repeat('b', 64)]);
        $importId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO import_audit_logs (import_id, action, created_at) VALUES (?, 'upload', '2020-01-01 00:00:00')"
        )->execute([$importId]);
        $userId = (int) $pdo->query("SELECT id FROM users WHERE email = 'super_admin@example.test'")->fetchColumn();
        $pdo->prepare(
            "INSERT INTO privacy_consents (subject_type, subject_id, purpose, status, channel, recorded_by, recorded_at)
             VALUES ('user', ?, 'account', 'granted', 'admin', ?, '2020-01-01 00:00:00')"
        )->execute([$userId, $userId]);
        AuditChain::sealUnsealed();

        $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $attemptCount = (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
        $applied = $super->request('POST', '/api/retention/apply', []);
        self::assertSame(200, $applied['status'], $applied['raw']);
        self::assertSame(0, $applied['body']['data']['deleted']);
        self::assertGreaterThanOrEqual(1, $applied['body']['data']['archived']['audit_logs']);
        self::assertGreaterThanOrEqual(1, $applied['body']['data']['archived']['login_attempts']);
        self::assertGreaterThanOrEqual(1, $applied['body']['data']['archived']['import_audit']);
        self::assertGreaterThanOrEqual(1, $applied['body']['data']['archived']['privacy_consents']);
        self::assertSame($auditCount + 1, (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
        self::assertSame($attemptCount, (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
        self::assertNotNull($pdo->query("SELECT archived_at FROM audit_logs WHERE action = 'old_retention'")->fetchColumn());
        self::assertNotNull($pdo->query('SELECT archived_at FROM login_attempts WHERE identifier_hash = \'' . str_repeat('a', 64) . '\'')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM import_audit_logs')->fetchColumn());
        self::assertNotNull($pdo->query('SELECT archived_at FROM import_audit_logs')->fetchColumn());
    }

    public function testDpdpConsentExportCorrectionAndAnonymise(): void
    {
        $admin = $this->signedInAs('admin');
        $sales = $this->signedInAs('sales_user', 'privacy-denied@example.test');
        self::assertError($sales->request('GET', '/api/privacy/consents'), 403, 'forbidden');

        $created = $admin->request('POST', '/api/users', [
            'name' => 'Meera Shah',
            'email' => 'meera.shah@example.test',
            'role' => 'sales_user',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(201, $created['status'], $created['raw']);
        $id = (int) $created['body']['data']['user']['id'];
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO employees (name, email, phone) VALUES (?, ?, ?)')
            ->execute(['Meera Shah', 'meera.shah@example.test', '9876543210']);
        $beforeUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $consent = $admin->request('POST', '/api/privacy/consents', [
            'subjectType' => 'user',
            'subjectId' => $id,
            'purpose' => 'account',
            'status' => 'granted',
            'channel' => 'admin',
            'notes' => 'Spoken with Meera',
        ]);
        self::assertSame(201, $consent['status'], $consent['raw']);
        self::assertSame('granted', $consent['body']['data']['consent']['status']);
        $withdrawn = $admin->request('POST', '/api/privacy/consents/' . $consent['body']['data']['consent']['id'] . '/withdraw', []);
        self::assertSame(200, $withdrawn['status'], $withdrawn['raw']);
        self::assertSame('withdrawn', $withdrawn['body']['data']['consent']['status']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM privacy_consents')->fetchColumn());

        $exportRequest = $admin->request('POST', '/api/privacy/requests', [
            'kind' => 'export',
            'subjectType' => 'user',
            'subjectId' => $id,
        ]);
        self::assertSame(201, $exportRequest['status'], $exportRequest['raw']);
        $export = $admin->request('POST', '/api/privacy/requests/' . $exportRequest['body']['data']['request']['id'] . '/fulfill', []);
        self::assertSame(200, $export['status'], $export['raw']);
        $encoded = json_encode($export['body']['data']['export']);
        self::assertStringContainsString('meera.shah@example.test', $encoded);
        self::assertStringContainsString('9876543210', $encoded);
        self::assertStringNotContainsString('password_hash', $encoded);
        self::assertStringNotContainsString('totp_secret', $encoded);
        self::assertSame('Meera Shah', $export['body']['data']['export']['linked']['name']);

        $correction = $admin->request('POST', '/api/privacy/requests', [
            'kind' => 'correction',
            'subjectType' => 'user',
            'subjectId' => $id,
            'detail' => 'Email should stay the same. Please record the request.',
        ]);
        $done = $admin->request('POST', '/api/privacy/requests/' . $correction['body']['data']['request']['id'] . '/fulfill', [
            'outcome' => 'Checked with the person. No change to the account.',
        ]);
        self::assertSame(200, $done['status'], $done['raw']);
        self::assertSame('fulfilled', $done['body']['data']['request']['status']);
        self::assertSame('meera.shah@example.test', $pdo->query('SELECT email FROM users WHERE id = ' . $id)->fetchColumn());

        $anon = $admin->request('POST', '/api/privacy/requests', [
            'kind' => 'anonymize',
            'subjectType' => 'user',
            'subjectId' => $id,
        ]);
        $masked = $admin->request('POST', '/api/privacy/requests/' . $anon['body']['data']['request']['id'] . '/fulfill', ['confirm' => true]);
        self::assertSame(200, $masked['status'], $masked['raw']);
        self::assertTrue($masked['body']['data']['subject']['anonymised']);
        self::assertSame($beforeUsers, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $row = $pdo->query('SELECT name, email, status, anonymised_at FROM users WHERE id = ' . $id)->fetch();
        self::assertSame('disabled', $row['status']);
        self::assertNotNull($row['anonymised_at']);
        self::assertStringNotContainsString('meera', strtolower((string) $row['email']));
        self::assertStringNotContainsString('Meera', (string) $row['name']);
        $employeeEmail = (string) $pdo->query("SELECT email FROM employees WHERE phone = '**********'")->fetchColumn();
        self::assertStringStartsWith('anonymised-employee-', $employeeEmail);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn());
        self::assertSame('[anonymised]', $pdo->query("SELECT notes FROM privacy_consents WHERE subject_id = {$id}")->fetchColumn());

        $actions = $pdo->query(
            "SELECT action FROM audit_logs WHERE module = 'privacy'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('privacy_consent_recorded', $actions);
        self::assertContains('privacy_consent_withdrawn', $actions);
        self::assertContains('privacy_exported', $actions);
        self::assertContains('privacy_request_fulfilled', $actions);
        self::assertContains('privacy_anonymised', $actions);
    }
}
