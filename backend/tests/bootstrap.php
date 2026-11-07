<?php

declare(strict_types=1);

/*
 * Test bootstrap:
 *  1. points the app at the TEST database (never the development one),
 *  2. applies pending migrations there,
 *  3. starts a PHP built-in API server on 127.0.0.1:8099 using that database.
 */

use App\Database\Database;
use App\Database\Migrator;
use Tests\Support\TestServer;

$testDb = getenv('TEST_DB_DATABASE') ?: 'yorilife_erp_test';
if (!str_ends_with($testDb, '_test')) {
    fwrite(STDERR, "Refusing to run: test database name must end in \"_test\" (got \"{$testDb}\").\n");
    exit(1);
}

// Real environment variables win over .env (see Env::load), so this forces the test DB.
putenv("DB_DATABASE={$testDb}");
putenv('APP_ENV=testing');
putenv('APP_DEBUG=false');

require dirname(__DIR__) . '/bootstrap.php';
restore_exception_handler();
restore_error_handler();

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Tests\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

(new Migrator(Database::connection(), dirname(__DIR__) . '/database/migrations'))->migrate(static fn () => null);
\App\Services\AuditChain::sealUnsealed();

TestServer::start($testDb);
