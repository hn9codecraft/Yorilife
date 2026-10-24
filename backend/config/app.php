<?php

declare(strict_types=1);

use App\Utils\Env;

return [
    'name' => Env::get('APP_NAME', 'Yorilife ERP API'),
    'version' => '0.1.0',
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::get('APP_DEBUG', false) === true,
    'url' => Env::get('APP_URL', 'http://localhost:8000'),
    'timezone' => Env::get('APP_TIMEZONE', 'Asia/Kolkata'),
    'cors_allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) Env::get('CORS_ALLOWED_ORIGINS', ''))
    ))),
];
