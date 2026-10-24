<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Server-side validation and normalisation of user fields. Returns the
 * cleaned value or records an error message in $errors[field].
 */
final class UserValidator
{
    /** @var array{min_length: int, max_bytes: int}|null */
    private static ?array $passwordRules = null;

    public static function normaliseEmail(mixed $value): string
    {
        return is_string($value) ? mb_strtolower(trim($value)) : '';
    }

    /** @param array<string, string> $errors */
    public static function email(mixed $value, array &$errors): ?string
    {
        $email = self::normaliseEmail($value);
        if ($email === '') {
            $errors['email'] = 'Enter an email address.';
        } elseif (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        } else {
            return $email;
        }

        return null;
    }

    /** @param array<string, string> $errors */
    public static function name(mixed $value, array &$errors): ?string
    {
        $name = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
        if ($name === '') {
            $errors['name'] = 'Enter a name.';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'] = 'Name must be 150 characters or fewer.';
        } elseif (preg_match('/[\p{Cc}\p{Cf}]/u', $name)) {
            $errors['name'] = 'Name contains invalid characters.';
        } else {
            return $name;
        }

        return null;
    }

    /** @param array<string, string> $errors */
    public static function newPassword(mixed $value, array &$errors): ?string
    {
        $rules = self::$passwordRules ??= (require dirname(__DIR__) . '/config/auth.php')['password'];

        if (!is_string($value) || $value === '') {
            $errors['password'] = 'Enter a password.';
        } elseif (mb_strlen($value) < $rules['min_length']) {
            $errors['password'] = "Password must be at least {$rules['min_length']} characters.";
        } elseif (strlen($value) > $rules['max_bytes']) {
            $errors['password'] = "Password must be at most {$rules['max_bytes']} bytes.";
        } else {
            return $value;
        }

        return null;
    }

    /** @param array<string, string> $errors */
    public static function status(mixed $value, array &$errors): ?string
    {
        if (in_array($value, ['active', 'disabled'], true)) {
            return $value;
        }
        $errors['status'] = 'Status must be "active" or "disabled".';

        return null;
    }

    /**
     * Reject any field the endpoint does not accept (e.g. "password_hash", "id").
     *
     * @param array<string, mixed> $input
     * @param list<string> $allowed
     * @param array<string, string> $errors
     */
    public static function onlyFields(array $input, array $allowed, array &$errors): void
    {
        foreach (array_diff(array_keys($input), $allowed) as $field) {
            $errors[(string) $field] = 'This field cannot be set here.';
        }
    }
}
