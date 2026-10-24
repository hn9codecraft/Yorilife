<?php

declare(strict_types=1);

use App\Utils\Env;

return [
    'driver' => 'mysql',
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', 3306),
    'database' => (string) Env::get('DB_DATABASE', ''),
    'username' => (string) Env::get('DB_USERNAME', ''),
    'password' => (string) Env::get('DB_PASSWORD', ''),
    'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
    // Seconds to wait for the MySQL server before failing.
    'timeout' => 5,
];
