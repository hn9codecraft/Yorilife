<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\Database;
use App\Services\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * Base class for API feature tests. Wipes business data, users and login
 * attempts in the TEST database before each test (roles and seeded financial
 * years from migrations are kept).
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

        self::wipeBusinessTables($pdo);
        $pdo->exec('DELETE FROM login_attempts');
        $pdo->exec('DELETE FROM users');
    }

    /**
     * Business tables, children before parents. Rows reference users (created_by
     * etc.), so they are cleared before users. Tables that do not exist yet are
     * skipped. The seeded financial years (created_by NULL) are kept.
     */
    public static function wipeBusinessTables(\PDO $pdo): void
    {
        $existing = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ([
            'sales_entries' => 'DELETE FROM sales_entries',
            'master_sheet_changes' => 'DELETE FROM master_sheet_changes',
            'master_sheet_entries' => 'DELETE FROM master_sheet_entries',
            'financial_years' => 'DELETE FROM financial_years WHERE created_by IS NOT NULL',
            'product_changes' => 'DELETE FROM product_changes',
            'product_terms' => 'DELETE FROM product_terms',
            'products' => 'DELETE FROM products',
        ] as $table => $sql) {
            if (in_array($table, $existing, true)) {
                $pdo->exec($sql);
            }
        }
        if (in_array('catalogue_terms', $existing, true)) {
            if ($pdo->query("SHOW COLUMNS FROM catalogue_terms LIKE 'parent_id'")->fetch()) {
                $pdo->exec('UPDATE catalogue_terms SET parent_id = NULL');
            }
            $pdo->exec('DELETE FROM catalogue_terms');
        }
        foreach ([
            'manager_states' => 'DELETE FROM manager_states',
            'location_headquarters' => 'DELETE FROM location_headquarters',
            'stockists' => 'DELETE FROM stockists',
            'headquarters' => 'DELETE FROM headquarters',
            'managers' => 'DELETE FROM managers ORDER BY level ASC',
        ] as $table => $sql) {
            if (in_array($table, $existing, true)) {
                $pdo->exec($sql);
            }
        }
        if (in_array('locations', $existing, true)) {
            $pdo->exec('DELETE FROM locations');
            $insert = $pdo->prepare('INSERT INTO locations (name, code) VALUES (?, ?)');
            foreach (require dirname(__DIR__, 2) . '/config/location_states.php' as $location) {
                $insert->execute([$location['name'], $location['code']]);
            }
        }
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
