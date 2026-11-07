<?php

declare(strict_types=1);

use App\Middleware\CorsMiddleware;
use App\Middleware\OriginMiddleware;
use App\Services\RequestContext;
use App\Utils\Router;
use App\Utils\Session;

/*
 * Front controller — the only PHP file exposed by the web server.
 * Point the document root at backend/public/.
 */

$appConfig = require dirname(__DIR__) . '/bootstrap.php';

Session::configure((require dirname(__DIR__) . '/config/auth.php')['session']);
RequestContext::boot(
    (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
    isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ((new CorsMiddleware($appConfig['cors_allowed_origins']))->handle($method)) {
    return;
}

$router = new Router();

// Browser requests that change state must come from the app itself or an allowed origin.
$appOrigin = rtrim((string) parse_url($appConfig['url'], PHP_URL_SCHEME) . '://' . parse_url($appConfig['url'], PHP_URL_HOST)
    . (parse_url($appConfig['url'], PHP_URL_PORT) ? ':' . parse_url($appConfig['url'], PHP_URL_PORT) : ''), '/');
$originGuard = new OriginMiddleware([$appOrigin, ...$appConfig['cors_allowed_origins']]);
$router->use([$originGuard, 'handle']);

require dirname(__DIR__) . '/routes/api.php';

// Supports hosting under a sub-folder (e.g. http://localhost/yorilife/backend/public).
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

$router->dispatch($method, $_SERVER['REQUEST_URI'] ?? '/', $basePath);
