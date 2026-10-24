<?php

declare(strict_types=1);

/*
 * Prepares the TEST database for the frontend end-to-end tests:
 * applies migrations, wipes users + login attempts, and creates fixture users.
 *
 *   E2E_PASSWORD=… php tests/e2e-seed.php
 *
 * Refuses to run unless the database name ends in "_test".
 * Fixture accounts exist only in the test database.
 */

use App\Database\Database;
use App\Database\Migrator;
use App\Services\PasswordHasher;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$testDb = getenv('TEST_DB_DATABASE') ?: 'yorilife_erp_test';
if (!str_ends_with($testDb, '_test')) {
    fwrite(STDERR, "Refusing to seed: \"{$testDb}\" is not a _test database.\n");
    exit(1);
}
putenv("DB_DATABASE={$testDb}");

require dirname(__DIR__) . '/bootstrap.php';
restore_exception_handler();

$password = getenv('E2E_PASSWORD');
if (!is_string($password) || strlen($password) < 12) {
    fwrite(STDERR, "Set E2E_PASSWORD (12+ characters).\n");
    exit(1);
}

$pdo = Database::connection();
(new Migrator($pdo, dirname(__DIR__) . '/database/migrations'))->migrate(static fn () => null);
$pdo->exec('DELETE FROM login_attempts');
$pdo->exec('DELETE FROM users');

$fixtures = [
    ['E2E Super Admin', 'e2e-owner@example.test', 'super_admin', 'active'],
    ['E2E Admin', 'e2e-admin@example.test', 'admin', 'active'],
    ['E2E Sales User', 'e2e-sales@example.test', 'sales_user', 'active'],
    ['E2E Disabled User', 'e2e-disabled@example.test', 'sales_user', 'disabled'],
];
$hash = PasswordHasher::hash($password);
$insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, role_id, status) SELECT ?, ?, ?, id, ? FROM roles WHERE slug = ?');
foreach ($fixtures as [$name, $email, $role, $status]) {
    $insert->execute([$name, $email, $hash, $status, $role]);
}

fwrite(STDOUT, sprintf("Seeded %d e2e users in %s.\n", count($fixtures), $testDb));
