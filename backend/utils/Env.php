<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Minimal .env loader and typed accessor.
 * Real environment variables (set by the server/OS) always take precedence over .env values.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));

            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }

            $value = self::stripQuotes($value);

            if (getenv($key) !== false || array_key_exists($key, $_ENV)) {
                continue;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            default => $value,
        };
    }

    private static function stripQuotes(string $value): string
    {
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        // Strip trailing inline comments for unquoted values: KEY=value # comment
        $commentPos = strpos($value, ' #');

        return $commentPos === false ? $value : rtrim(substr($value, 0, $commentPos));
    }
}
