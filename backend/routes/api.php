<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\UserController;
use App\Middleware\AuthMiddleware;
use App\Utils\Router;

/** @var Router $router */

// System (public)
$router->get('/api/health', [HealthController::class, 'index']);
$router->get('/api/health/database', [HealthController::class, 'database']);

// Authentication
$auth = AuthMiddleware::authenticated();
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout'], [$auth]);
$router->get('/api/auth/me', [AuthController::class, 'me'], [$auth]);

// User management (Sprint 3 foundation). Fine-grained rules are in UserService.
$router->get('/api/roles', [UserController::class, 'roles'], [$auth, AuthMiddleware::can('users.view')]);
$router->get('/api/users', [UserController::class, 'index'], [$auth, AuthMiddleware::can('users.view')]);
$router->post('/api/users', [UserController::class, 'store'], [$auth, AuthMiddleware::can('users.create')]);
$router->get('/api/users/{id}', [UserController::class, 'show'], [$auth, AuthMiddleware::can('users.view')]);
$router->patch('/api/users/{id}', [UserController::class, 'update'], [$auth, AuthMiddleware::can('users.view')]);

// Business-module endpoints (Sales Office, Products, Sales, Imports) are not part
// of Sprint 3. When added, protect them with $auth plus an approved permission.
