<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

final class AuthenticationTest extends FeatureTestCase
{
    public function testValidLoginReturnsUserAndSetsHardenedSessionCookie(): void
    {
        $this->createUser('asha@example.test', 'admin');
        $client = new ApiClient();

        $response = $client->login('  Asha@Example.TEST ', self::PASSWORD);

        self::assertSame(200, $response['status'], $response['raw']);
        $user = $response['body']['data']['user'];
        self::assertSame('asha@example.test', $user['email']);
        self::assertSame('admin', $user['role']['slug']);
        self::assertContains('users.view', $user['permissions']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response['body']['data']['csrfToken']);
        self::assertArrayNotHasKey('password_hash', $user);
        self::assertStringNotContainsString('$2y$', $response['raw']);

        $cookie = $response['headers']['set-cookie'] ?? '';
        self::assertStringContainsString('yorilife_session=', $cookie);
        self::assertStringContainsStringIgnoringCase('HttpOnly', $cookie);
        self::assertStringContainsStringIgnoringCase('SameSite=Lax', $cookie);
    }

    public function testLoginRegeneratesSessionIdToPreventFixation(): void
    {
        $this->createUser('fixation@example.test');
        $client = new ApiClient();
        $client->cookies['yorilife_session'] = 'attackerchosenid1234567890';

        $client->login('fixation@example.test', self::PASSWORD);

        self::assertNotSame('attackerchosenid1234567890', $client->sessionId());
        self::assertNotNull($client->sessionId());
    }

    public function testWrongPasswordAndUnknownEmailGiveTheSameGenericError(): void
    {
        $this->createUser('known@example.test');

        $wrongPassword = (new ApiClient())->login('known@example.test', 'not-the-password');
        $unknownEmail = (new ApiClient())->login('nobody@example.test', 'not-the-password');

        self::assertError($wrongPassword, 401, 'invalid_credentials');
        self::assertError($unknownEmail, 401, 'invalid_credentials');
        self::assertSame($wrongPassword['body']['error']['message'], $unknownEmail['body']['error']['message']);
        self::assertArrayNotHasKey('set-cookie', $wrongPassword['headers']);
    }

    public function testDisabledAccountCannotSignIn(): void
    {
        $this->createUser('disabled@example.test', 'admin', 'disabled');

        $response = (new ApiClient())->login('disabled@example.test', self::PASSWORD);

        self::assertError($response, 403, 'account_disabled');
        self::assertArrayNotHasKey('set-cookie', $response['headers']);
    }

    /** @return iterable<string, array{0: mixed, 1: list<string>}> */
    public static function invalidLoginPayloads(): iterable
    {
        yield 'empty object' => [[], ['email', 'password']];
        yield 'malformed email' => [['email' => 'not-an-email', 'password' => 'x'], ['email']];
        yield 'non-string password' => [['email' => 'a@example.test', 'password' => 12345], ['password']];
        yield 'unexpected field' => [['email' => 'a@example.test', 'password' => 'x', 'role' => 'super_admin'], ['role']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLoginPayloads')]
    public function testMissingOrMalformedInputIsRejected(array $payload, array $fields): void
    {
        $response = (new ApiClient())->request('POST', '/api/auth/login', $payload);

        self::assertError($response, 422, 'validation_failed');
        foreach ($fields as $field) {
            self::assertArrayHasKey($field, $response['body']['error']['details']['fields']);
        }
    }

    public function testNonJsonAndInvalidJsonBodiesAreRejected(): void
    {
        $client = new ApiClient();

        $form = $client->request('POST', '/api/auth/login', 'email=a@example.test&password=x', ['Content-Type' => 'application/x-www-form-urlencoded']);
        $broken = $client->request('POST', '/api/auth/login', '{"email":', ['Content-Type' => 'application/json']);

        self::assertError($form, 415, 'unsupported_media_type');
        self::assertError($broken, 400, 'invalid_json');
    }

    public function testRepeatedFailuresAreThrottled(): void
    {
        $this->createUser('target@example.test');
        $client = new ApiClient();

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $client->login('target@example.test', 'wrong-password-' . $i)['status']);
        }
        $blocked = $client->login('target@example.test', self::PASSWORD);

        self::assertError($blocked, 429, 'too_many_attempts');
        self::assertGreaterThan(0, (int) ($blocked['headers']['retry-after'] ?? 0));
    }

    public function testMeReturnsTheSignedInUserAndRestoresStateFromTheCookie(): void
    {
        $client = $this->signedInAs('sales_manager');

        // A fresh client with only the cookie = a browser reload (no in-memory state).
        $reloaded = new ApiClient();
        $reloaded->cookies = $client->cookies;
        $response = $reloaded->request('GET', '/api/auth/me');

        self::assertSame(200, $response['status'], $response['raw']);
        self::assertSame('sales_manager', $response['body']['data']['user']['role']['slug']);
        // Sprint 4: sales roles may view the business screens (owner-approved).
        self::assertSame(['sales_office.view', 'catalogue.view', 'products.view', 'master_sheet.view', 'sales.view'], $response['body']['data']['user']['permissions']);
        self::assertSame($client->csrfToken, $response['body']['data']['csrfToken']);
    }

    public function testMeWithoutSessionOrWithForgedCookieIsUnauthenticated(): void
    {
        $anonymous = (new ApiClient())->request('GET', '/api/auth/me');

        $forged = new ApiClient();
        $forged->cookies['yorilife_session'] = 'forgedsessionid0000000000000';
        $forgedResponse = $forged->request('GET', '/api/auth/me');

        self::assertError($anonymous, 401, 'unauthenticated');
        self::assertSame(401, $forgedResponse['status']);
    }

    public function testLogoutInvalidatesTheServerSideSession(): void
    {
        $client = $this->signedInAs('admin');
        $stolenCookies = $client->cookies;

        $logout = $client->request('POST', '/api/auth/logout');
        self::assertSame(200, $logout['status'], $logout['raw']);

        // Replaying the old cookie after logout must not work.
        $replay = new ApiClient();
        $replay->cookies = $stolenCookies;
        self::assertSame(401, $replay->request('GET', '/api/auth/me')['status']);
    }

    public function testLogoutRequiresTheCsrfToken(): void
    {
        $client = $this->signedInAs('admin');

        $response = $client->request('POST', '/api/auth/logout', withCsrf: false);

        self::assertError($response, 403, 'csrf_token_invalid');
        self::assertSame(200, $client->request('GET', '/api/auth/me')['status'], 'session must survive a rejected logout');
    }

    public function testIdleSessionExpires(): void
    {
        $client = $this->signedInAs('admin');
        $this->editSession($client, 'last_activity_at', time() - 31 * 60);

        self::assertError($client->request('GET', '/api/auth/me'), 401, 'session_expired');
        self::assertSame(401, $client->request('GET', '/api/auth/me')['status'], 'expired session is destroyed');
    }

    public function testSessionExpiresAfterAbsoluteLifetimeEvenWhenActive(): void
    {
        $client = $this->signedInAs('admin');
        $this->editSession($client, 'authenticated_at', time() - 13 * 3600);

        self::assertError($client->request('GET', '/api/auth/me'), 401, 'session_expired');
    }

    public function testDisablingAnAccountEndsItsExistingSession(): void
    {
        $client = $this->signedInAs('sales_user', 'soon-disabled@example.test');
        \App\Database\Database::connection()->exec("UPDATE users SET status = 'disabled' WHERE email = 'soon-disabled@example.test'");

        self::assertError($client->request('GET', '/api/auth/me'), 401, 'session_expired');
    }

    public function testStateChangingRequestFromForeignOriginIsRejected(): void
    {
        $this->createUser('origin@example.test');

        $response = (new ApiClient())->request('POST', '/api/auth/login', ['email' => 'origin@example.test', 'password' => self::PASSWORD], ['Origin' => 'https://evil.example']);

        self::assertError($response, 403, 'origin_not_allowed');
    }

    public function testHealthEndpointsStayPublic(): void
    {
        $client = new ApiClient();

        self::assertSame(200, $client->request('GET', '/api/health')['status']);
        $db = $client->request('GET', '/api/health/database');
        self::assertSame(200, $db['status'], $db['raw']);
        self::assertSame('connected', $db['body']['data']['database']['status']);
        self::assertArrayNotHasKey('set-cookie', $db['headers'], 'public endpoints must not start sessions');
    }
}
