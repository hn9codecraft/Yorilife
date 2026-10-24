<?php

declare(strict_types=1);

/*
 * Apply pending database migrations.
 *
 *   php bin/migrate.php            show the target database, then apply pending migrations
 *   php bin/migrate.php --status   only show applied / pending migrations
 *
 * The target is the database in .env (override with the DB_DATABASE environment
 * variable, e.g. for the test database). Nothing is ever dropped.
 */

use App\Database\Database;
use App\Database\Migrator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/bootstrap.php';
restore_exception_handler();

$dbConfig = require dirname(__DIR__) . '/config/database.php';
$migrator = new Migrator(Database::connection(), dirname(__DIR__) . '/database/migrations');

$tables = Database::connection()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
fwrite(STDOUT, sprintf("Database: %s@%s:%d/%s\n", $dbConfig['username'], $dbConfig['host'], $dbConfig['port'], $dbConfig['database']));
fwrite(STDOUT, 'Existing tables: ' . ($tables ? implode(', ', $tables) : '(none)') . "\n");

$applied = $migrator->applied();
$pending = $migrator->pending();
fwrite(STDOUT, 'Applied: ' . ($applied ? implode(', ', $applied) : '(none)') . "\n");
fwrite(STDOUT, 'Pending: ' . ($pending ? implode(', ', $pending) : '(none)') . "\n");

if (in_array('--status', $argv, true) || $pending === []) {
    exit(0);
}

try {
    $done = $migrator->migrate(static fn (string $line) => fwrite(STDOUT, $line . "\n"));
    fwrite(STDOUT, sprintf("Done. %d migration(s) applied.\n", count($done)));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
