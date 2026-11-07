<?php

declare(strict_types=1);

/*
 * Archive rows that are older than the retention settings.
 *
 *   php bin/apply-retention.php
 *
 * Nothing is deleted. Safe to run from a daily job. The target database is the
 * one in .env (override with DB_DATABASE).
 */

use App\Services\RequestContext;
use App\Services\RetentionEnforcer;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';
restore_exception_handler();

RequestContext::boot('127.0.0.1', 'retention-job');
$result = (new RetentionEnforcer())->apply(null);
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
