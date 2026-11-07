<?php

declare(strict_types=1);

/*
 * Archive rows that are older than the retention settings.
 *
 *   php bin/archive-retention.php
 *
 * Rows are marked archived. Nothing is deleted.
 */

use App\Database\Database;
use App\Services\RetentionEnforcer;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';
restore_exception_handler();

$database = (string) Database::connection()->query('SELECT DATABASE()')->fetchColumn();
if (str_ends_with($database, '_test')) {
    fwrite(STDERR, "Refusing to archive the test database \"{$database}\".\n");
    exit(1);
}

$result = RetentionEnforcer::apply(null);
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
