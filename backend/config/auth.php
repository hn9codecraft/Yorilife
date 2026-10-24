<?php

declare(strict_types=1);

use App\Utils\Env;

$secureCookie = Env::get('SESSION_SECURE_COOKIE', Env::get('APP_ENV', 'production') !== 'local') === true;

return [
    'session' => [
        // "__Host-" prefix (browser-enforced Secure + Path=/ + no Domain) whenever the cookie is Secure.
        'cookie_name' => ($secureCookie ? '__Host-' : '') . Env::get('SESSION_COOKIE_NAME', 'yorilife_session'),
        'secure' => $secureCookie,
        'same_site' => Env::get('SESSION_SAME_SITE', 'Lax'),
        // Signed out after this many minutes without a request…
        'idle_timeout_minutes' => (int) Env::get('SESSION_IDLE_TIMEOUT_MINUTES', 30),
        // …and always after this many hours, however active.
        'absolute_lifetime_hours' => (int) Env::get('SESSION_ABSOLUTE_LIFETIME_HOURS', 12),
        'save_path' => Env::get('SESSION_SAVE_PATH', dirname(__DIR__) . '/storage/sessions'),
    ],

    // Failed-login throttling (stored in the login_attempts table).
    'throttle' => [
        'window_minutes' => (int) Env::get('LOGIN_THROTTLE_WINDOW_MINUTES', 15),
        'max_failures_per_account' => (int) Env::get('LOGIN_MAX_FAILURES_PER_ACCOUNT', 5),
        'max_failures_per_ip' => (int) Env::get('LOGIN_MAX_FAILURES_PER_IP', 30),
    ],

    'password' => [
        'min_length' => 12,
        // bcrypt only uses the first 72 bytes — reject longer instead of truncating silently.
        'max_bytes' => 72,
    ],
];
