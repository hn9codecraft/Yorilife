<?php

declare(strict_types=1);

/*
 * Insert or refresh fictional Sales Office demo rows in the development database.
 *
 *   php bin/seed-demo.php
 *
 * Idempotent: rows are matched by their employee and office codes. A second run
 * updates those rows and does not create duplicates. Leftover DEMO- office rows
 * that are not in this set are removed. Rows without those codes, and all
 * users, are left alone.
 *
 * Refuses APP_ENV=production and any database whose name ends in _test.
 * If the database is not yorilife_erp, pass --confirm after checking the name it prints.
 */

use App\Database\Database;
use App\Database\DemoSalesOfficeSeeder;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';
restore_exception_handler();

$fail = static function (string $message): never {
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
};

$app = require dirname(__DIR__) . '/config/app.php';
if (($app['env'] ?? '') === 'production') {
    $fail('Refusing to seed demo data when APP_ENV=production.');
}

if (!Database::isConfigured()) {
    $fail('Database is not configured. Set DB_DATABASE and DB_USERNAME in backend/.env.');
}

$pdo = Database::connection();
$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
fwrite(STDOUT, "Target database: {$database}\n");

if (str_ends_with($database, '_test')) {
    $fail('Refusing to seed a test database. Automated tests call DemoSalesOfficeSeeder directly.');
}
if ($database !== 'yorilife_erp' && !in_array('--confirm', $argv, true)) {
    $fail('This is not yorilife_erp. Re-run with --confirm only if this database is the intended development database.');
}

$summary = (new DemoSalesOfficeSeeder())->seed();
fwrite(STDOUT, sprintf(
    "Demo Sales Office data ready in %s.\nHeadquarters: %d\nManagers: %d (1 third line, 4 second line, 4 first line)\nStockists: %d\nCreated this run: %d\nUpdated this run: %d\n",
    $database,
    $summary['headquarters'],
    $summary['managers'],
    $summary['stockists'],
    $summary['created'],
    $summary['updated'],
));
