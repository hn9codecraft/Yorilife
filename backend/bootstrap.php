<?php

declare(strict_types=1);

use App\Database\Database;
use App\Utils\Env;
use App\Utils\JsonResponse;

/*
 * Application bootstrap: autoloading, environment, error handling and database config.
 * Shared by public/index.php and any future CLI scripts.
 */

$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"success":false,"error":{"code":"setup_incomplete","message":"Run composer install in the backend folder."}}';
    exit(1);
}

require $autoload;

Env::load(__DIR__ . '/.env');

$appConfig = require __DIR__ . '/config/app.php';

date_default_timezone_set($appConfig['timezone']);

// A JSON API must never print PHP errors into the response body.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e) use ($appConfig): void {
    error_log(sprintf('[api] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));

    $message = $appConfig['debug'] ? $e->getMessage() : 'An unexpected error occurred.';

    JsonResponse::error('internal_error', $message, 500);
});

Database::configure(require __DIR__ . '/config/database.php');

return $appConfig;
