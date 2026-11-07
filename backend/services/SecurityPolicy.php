<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use Throwable;

/**
 * Security and list settings stored in the database, with the catalogue
 * defaults when a row is missing. Read on each use so a change applies on the
 * next request (the built-in PHP server keeps the process alive).
 */
final class SecurityPolicy
{
    /** @return array{min_length: int, max_bytes: int, require_uppercase: bool, require_lowercase: bool, require_number: bool, require_symbol: bool} */
    public static function password(): array
    {
        $auth = self::fileConfig();

        return [
            'min_length' => self::int('security.password_min_length', 12, 8, 64),
            'max_bytes' => (int) ($auth['password']['max_bytes'] ?? 72),
            'require_uppercase' => self::bool('security.password_require_uppercase'),
            'require_lowercase' => self::bool('security.password_require_lowercase'),
            'require_number' => self::bool('security.password_require_number'),
            'require_symbol' => self::bool('security.password_require_symbol'),
        ];
    }

    public static function sessionIdleMinutes(): int
    {
        return self::int('security.session_idle_minutes', (int) (self::fileConfig()['session']['idle_timeout_minutes'] ?? 30), 5, 720);
    }

    public static function sessionAbsoluteHours(): int
    {
        return self::int('security.session_absolute_hours', (int) (self::fileConfig()['session']['absolute_lifetime_hours'] ?? 12), 1, 168);
    }

    public static function lockoutMaxFailures(): int
    {
        return self::int('security.lockout_max_failures', (int) (self::fileConfig()['throttle']['max_failures_per_account'] ?? 5), 3, 20);
    }

    public static function lockoutWindowMinutes(): int
    {
        return self::int('security.lockout_window_minutes', (int) (self::fileConfig()['throttle']['window_minutes'] ?? 15), 1, 1440);
    }

    public static function recordsPerPage(): int
    {
        $value = self::int('general.records_per_page', 50, 25, 100);

        return in_array($value, [25, 50, 100], true) ? $value : 50;
    }

    /** @return array<string, mixed> safe for the users screen; no secrets */
    public static function describe(): array
    {
        $password = self::password();
        $rules = [];
        if ($password['require_uppercase']) {
            $rules[] = 'an uppercase letter';
        }
        if ($password['require_lowercase']) {
            $rules[] = 'a lowercase letter';
        }
        if ($password['require_number']) {
            $rules[] = 'a number';
        }
        if ($password['require_symbol']) {
            $rules[] = 'a symbol';
        }

        return [
            'minLength' => $password['min_length'],
            'maxBytes' => $password['max_bytes'],
            'requireUppercase' => $password['require_uppercase'],
            'requireLowercase' => $password['require_lowercase'],
            'requireNumber' => $password['require_number'],
            'requireSymbol' => $password['require_symbol'],
            'summary' => $rules === []
                ? "At least {$password['min_length']} characters."
                : "At least {$password['min_length']} characters, including " . implode(', ', $rules) . '.',
        ];
    }

    public static function int(string $key, int $default, int $min, int $max): int
    {
        $raw = self::stored($key);
        if ($raw === null || preg_match('/^-?\d+$/', $raw) !== 1) {
            return $default;
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }

    private static function bool(string $key): bool
    {
        return self::stored($key) === '1';
    }

    private static function stored(string $key): ?string
    {
        try {
            $stmt = Database::connection()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            if ($value === false || $value === null) {
                return null;
            }

            return (string) $value;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private static function fileConfig(): array
    {
        return require dirname(__DIR__) . '/config/auth.php';
    }
}
