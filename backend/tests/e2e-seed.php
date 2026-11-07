<?php

declare(strict_types=1);

/*
 * Prepares the TEST database for the frontend end-to-end tests:
 * applies migrations, wipes business data, users + login attempts, and creates
 * fixture users.
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
// Business rows reference users, so they go first (children before parents).
$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ([
    'audit_logs' => 'DELETE FROM audit_logs',
    'entity_changes' => 'DELETE FROM entity_changes',
    'notification_outbox' => 'DELETE FROM notification_outbox',
    'announcement_changes' => 'DELETE FROM announcement_changes',
    'announcement_attachments' => 'DELETE FROM announcement_attachments',
    'announcement_recipients' => 'DELETE FROM announcement_recipients',
    'announcement_audience_rules' => 'DELETE FROM announcement_audience_rules',
    'announcements' => 'DELETE FROM announcements',
    'announcement_templates' => 'DELETE FROM announcement_templates',
    'employees' => 'DELETE FROM employees',
    'import_rollbacks' => 'DELETE FROM import_rollbacks',
    'import_audit_logs' => 'DELETE FROM import_audit_logs',
    'import_changes' => 'DELETE FROM import_changes',
    'import_errors' => 'DELETE FROM import_errors',
    'imports' => 'DELETE FROM imports',
    'sales_entries' => 'DELETE FROM sales_entries',
    'master_sheet_changes' => 'DELETE FROM master_sheet_changes',
    'master_sheet_entries' => 'DELETE FROM master_sheet_entries',
    'financial_years' => 'DELETE FROM financial_years WHERE created_by IS NOT NULL',
    'product_changes' => 'DELETE FROM product_changes',
    'product_terms' => 'DELETE FROM product_terms',
    'products' => 'DELETE FROM products',
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
    $insertLocation = $pdo->prepare('INSERT INTO locations (name, code) VALUES (?, ?)');
    foreach (require dirname(__DIR__) . '/config/location_states.php' as $location) {
        $insertLocation->execute([$location['name'], $location['code']]);
    }
}
if (in_array('catalogue_terms', $existing, true)) {
    if ($pdo->query("SHOW COLUMNS FROM catalogue_terms LIKE 'parent_id'")->fetch()) {
        $pdo->exec('UPDATE catalogue_terms SET parent_id = NULL');
    }
    $pdo->exec('DELETE FROM catalogue_terms');
}
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
