<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\Database;
use App\Services\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * Base class for API feature tests. Wipes users and login attempts in the
 * TEST database before each test (roles from migrations are kept).
 */
abstract class FeatureTestCase extends TestCase
{
    protected const PASSWORD = 'Correct-Horse-Battery-9';

    protected function setUp(): void
    {
        $pdo = Database::connection();
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!str_ends_with($database, '_test')) {
            self::fail("Refusing to wipe non-test database \"{$database}\".");
        }

        $pdo->exec('DELETE FROM login_attempts');
        $pdo->exec('DELETE FROM users');
    }

    /** Insert a user directly (test fixture) and return its id. */
    protected function createUser(string $email, string $role = 'sales_user', string $status = 'active', string $name = 'Test User'): int
    {
        $pdo = Database::connection();
        $roleId = $pdo->prepare('SELECT id FROM roles WHERE slug = ?');
        $roleId->execute([$role]);

        $pdo->prepare('INSERT INTO users (name, email, password_hash, role_id, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, $email, PasswordHasher::hash(self::PASSWORD), (int) $roleId->fetchColumn(), $status]);

        return (int) $pdo->lastInsertId();
    }

    protected function signedInAs(string $role, ?string $email = null): ApiClient
    {
        $email ??= "{$role}@example.test";
        $this->createUser($email, $role, 'active', ucwords(str_replace('_', ' ', $role)));
        $client = new ApiClient();
        $response = $client->login($email, self::PASSWORD);
        self::assertSame(200, $response['status'], 'login failed: ' . $response['raw']);

        return $client;
    }

    /** Rewrite a value stored in the server-side session file (to simulate time passing). */
    protected function editSession(ApiClient $client, string $key, int $value): void
    {
        $file = TestServer::sessionPath() . '/sess_' . $client->sessionId();
        self::assertFileExists($file);
        $data = (string) file_get_contents($file);
        $updated = preg_replace('/' . preg_quote($key, '/') . '\|i:\d+;/', "{$key}|i:{$value};", $data, 1, $count);
        self::assertSame(1, $count, "session key {$key} not found");
        file_put_contents($file, $updated);
    }

    protected static function assertError(array $response, int $status, string $code): void
    {
        self::assertSame($status, $response['status'], $response['raw']);
        self::assertFalse($response['body']['success'] ?? null);
        self::assertSame($code, $response['body']['error']['code'] ?? null, $response['raw']);
    }
}
