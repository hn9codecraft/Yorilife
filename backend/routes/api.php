<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CatalogueController;
use App\Controllers\ErpController;
use App\Controllers\HealthController;
use App\Controllers\ProductController;
use App\Controllers\SalesEntryController;
use App\Controllers\SalesOfficeController;
use App\Controllers\SearchController;
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

// Business data (Sprint 4). Viewing is open to every sales role; changes follow
// the permission map. Managers and location states can be deleted only when
// nothing still depends on them. Headquarters, stockists and sales entries
// have no delete route.
$router->get('/api/erp', [ErpController::class, 'state'], [$auth, AuthMiddleware::can('sales_office.view')]);
$router->get('/api/search', [SearchController::class, 'index'], [$auth]);

$router->get('/api/headquarters', [SalesOfficeController::class, 'headquarters'], [$auth, AuthMiddleware::can('sales_office.view')]);
$router->post('/api/headquarters', [SalesOfficeController::class, 'storeHeadquarters'], [$auth, AuthMiddleware::can('sales_office.manage')]);
$router->patch('/api/headquarters/{id}', [SalesOfficeController::class, 'updateHeadquarters'], [$auth, AuthMiddleware::can('sales_office.manage')]);
$router->post('/api/headquarters/{id}/delete', [SalesOfficeController::class, 'deleteHeadquarters'], [$auth, AuthMiddleware::can('sales_office.manage')]);

$router->get('/api/locations', [SalesOfficeController::class, 'locations'], [$auth, AuthMiddleware::can('sales_office.view')]);
$router->post('/api/locations', [SalesOfficeController::class, 'storeLocation'], [$auth, AuthMiddleware::can('sales_office.manage')]);
$router->post('/api/location-headquarters', [SalesOfficeController::class, 'storeLocationHeadquarters'], [$auth, AuthMiddleware::can('sales_office.manage')]);
$router->patch('/api/location-headquarters/{id}', [SalesOfficeController::class, 'updateLocationHeadquarters'], [$auth, AuthMiddleware::can('sales_office.manage')]);
$router->post('/api/location-headquarters/{id}/delete', [SalesOfficeController::class, 'deleteLocationHeadquarters'], [$auth, AuthMiddleware::can('sales_office.manage')]);
$router->patch('/api/locations/{id}', [SalesOfficeController::class, 'updateLocation'], [$auth, AuthMiddleware::can('sales_office.manage')]);
$router->post('/api/locations/{id}/delete', [SalesOfficeController::class, 'deleteLocation'], [$auth, AuthMiddleware::can('sales_office.manage')]);

$router->get('/api/managers', [SalesOfficeController::class, 'managers'], [$auth, AuthMiddleware::can('sales_office.view')]);
$router->post('/api/managers', [SalesOfficeController::class, 'storeManager'], [$auth, AuthMiddleware::can('sales_office.manage')]);
$router->patch('/api/managers/{id}', [SalesOfficeController::class, 'updateManager'], [$auth, AuthMiddleware::can('sales_office.manage')]);
$router->post('/api/managers/{id}/delete', [SalesOfficeController::class, 'deleteManager'], [$auth, AuthMiddleware::can('sales_office.manage')]);

$router->post('/api/sales-office/import', [SalesOfficeController::class, 'import'], [$auth, AuthMiddleware::can('sales_office.manage')]);

$router->get('/api/stockists', [SalesOfficeController::class, 'stockists'], [$auth, AuthMiddleware::can('sales_office.view')]);
$router->post('/api/stockists', [SalesOfficeController::class, 'storeStockist'], [$auth, AuthMiddleware::can('sales_office.manage')]);
$router->patch('/api/stockists/{id}', [SalesOfficeController::class, 'updateStockist'], [$auth, AuthMiddleware::can('sales_office.manage')]);
$router->post('/api/stockists/{id}/delete', [SalesOfficeController::class, 'deleteStockist'], [$auth, AuthMiddleware::can('sales_office.manage')]);

$router->get('/api/catalogue-terms', [CatalogueController::class, 'index'], [$auth, AuthMiddleware::can('catalogue.view')]);
$router->post('/api/catalogue-terms', [CatalogueController::class, 'store'], [$auth, AuthMiddleware::can('catalogue.manage')]);
$router->patch('/api/catalogue-terms/{id}', [CatalogueController::class, 'update'], [$auth, AuthMiddleware::can('catalogue.manage')]);
$router->post('/api/catalogue-terms/{id}/delete', [CatalogueController::class, 'delete'], [$auth, AuthMiddleware::can('catalogue.manage')]);
$router->post('/api/catalogue-terms/{id}/restore', [CatalogueController::class, 'restore'], [$auth, AuthMiddleware::can('catalogue.restore')]);

$router->get('/api/products', [ProductController::class, 'index'], [$auth, AuthMiddleware::can('products.view')]);
$router->post('/api/products', [ProductController::class, 'store'], [$auth, AuthMiddleware::can('products.manage')]);
$router->patch('/api/products/{id}', [ProductController::class, 'update'], [$auth, AuthMiddleware::can('products.manage')]);
$router->post('/api/products/{id}/delete', [ProductController::class, 'delete'], [$auth, AuthMiddleware::can('products.delete')]);
$router->post('/api/products/{id}/restore', [ProductController::class, 'restore'], [$auth, AuthMiddleware::can('products.restore')]);

$router->post('/api/financial-years', [ErpController::class, 'storeYear'], [$auth, AuthMiddleware::can('financial_years.manage')]);
$router->get('/api/master-sheet/{year}/scan', [ErpController::class, 'scanSheet'], [$auth, AuthMiddleware::can('master_sheet.update')]);
$router->post('/api/master-sheet/{year}/update-products', [ErpController::class, 'updateSheetProducts'], [$auth, AuthMiddleware::can('master_sheet.update')]);
$router->post('/api/master-sheet/{year}/add-products', [ErpController::class, 'addSheetProducts'], [$auth, AuthMiddleware::can('master_sheet.update')]);
$router->put('/api/master-sheet/{year}/{productId}', [ErpController::class, 'saveSheet'], [$auth, AuthMiddleware::can('master_sheet.update')]);

$router->get('/api/sales-entries', [SalesEntryController::class, 'index'], [$auth, AuthMiddleware::can('sales.view')]);
$router->post('/api/sales-entries', [SalesEntryController::class, 'store'], [$auth, AuthMiddleware::can('sales.create')]);
$router->patch('/api/sales-entries/{id}', [SalesEntryController::class, 'update'], [$auth, AuthMiddleware::can('sales.update')]);
