<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\HttpException;

/**
 * Shared server-side validation for the business modules. Each helper returns
 * the cleaned value or records a message in $errors[field] (camelCase keys,
 * matching the web forms).
 */
final class Input
{
    /** @param array<string, string> $errors */
    public static function onlyFields(array $input, array $allowed, array &$errors): void
    {
        foreach (array_diff(array_keys($input), $allowed) as $field) {
            $errors[(string) $field] = 'This field cannot be set here.';
        }
    }

    /** Required single-line text: trimmed, inner whitespace collapsed, no control characters. */
    public static function text(array $input, string $field, string $label, int $max, array &$errors): ?string
    {
        $value = self::clean($input[$field] ?? null);
        if ($value === null || $value === '') {
            $errors[$field] = "Enter the {$label}.";
        } elseif (mb_strlen($value) > $max) {
            $errors[$field] = ucfirst($label) . " must be {$max} characters or fewer.";
        } elseif (preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
            $errors[$field] = ucfirst($label) . ' contains invalid characters.';
        } else {
            return $value;
        }

        return null;
    }

    /** Optional text: empty or missing becomes null. */
    public static function optionalText(array $input, string $field, string $label, int $max, array &$errors): ?string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null) {
            return null;
        }
        $value = self::clean($input[$field]);
        if ($value === null) {
            $errors[$field] = ucfirst($label) . ' must be text.';
            return null;
        }
        if ($value === '') {
            return null;
        }

        return self::text([$field => $value], $field, $label, $max, $errors);
    }

    /** A record id sent as a JSON number or digit string; null when empty. */
    public static function id(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && $value !== '' && strlen($value) < 19 && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /** @return list<int>|null list of ids, or null when not a list of valid ids */
    public static function idList(mixed $value): ?array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > 200) {
            return null;
        }
        $ids = [];
        foreach ($value as $item) {
            $id = self::id($item);
            if ($id === null) {
                return null;
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /**
     * INR amount: not negative, at most two decimal places. Empty becomes null.
     * Returns a DECIMAL string ("10.50") so the value is stored exactly.
     *
     * @param array<string, string> $errors
     */
    public static function money(array $input, string $field, string $label, array &$errors): ?string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            return null;
        }
        $value = $input[$field];
        if (is_string($value) && preg_match('/^\d+(\.\d{1,2})?$/', trim($value))) {
            $value = (float) trim($value);
        }
        if (!is_int($value) && !is_float($value)) {
            $errors[$field] = "{$label} must be a number with at most two decimal places.";

            return null;
        }
        if ($value < 0) {
            $errors[$field] = "{$label} cannot be negative.";

            return null;
        }
        $cents = round($value * 100);
        if (abs($value * 100 - $cents) > 0.000001 || $cents > 99999999999999) {
            $errors[$field] = $cents > 99999999999999
                ? "{$label} is too large."
                : "{$label} must be a number with at most two decimal places.";

            return null;
        }

        return number_format($cents / 100, 2, '.', '');
    }

    /** YYYY-MM → the first day of that month (DATE), or null. */
    public static function month(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})$/', $value, $match)) {
            return null;
        }
        $year = (int) $match[1];
        $month = (int) $match[2];
        if ($year < 2000 || $year > 2100 || !checkdate($month, 1, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-01', $year, $month);
    }

    /**
     * April–March label such as 2025-2026.
     *
     * @return array{label: string, startsOn: string, endsOn: string}|null
     */
    public static function financialYear(mixed $value): ?array
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{4})$/', trim($value), $match)) {
            return null;
        }
        $start = (int) $match[1];
        $end = (int) $match[2];
        if ($end !== $start + 1 || $start < 2000 || $start > 2100) {
            return null;
        }

        return [
            'label' => sprintf('%04d-%04d', $start, $end),
            'startsOn' => sprintf('%04d-04-01', $start),
            'endsOn' => sprintf('%04d-03-31', $end),
        ];
    }

    /**
     * Scheme text is stored exactly. "10+5" also records buy 10 and free 5.
     * Any other non-empty text is kept with no quantities.
     *
     * @return array{text: ?string, buy: ?int, free: ?int}
     */
    public static function scheme(mixed $value): array
    {
        $text = is_string($value) ? trim(preg_replace('/[ \t\r\n]+/u', ' ', $value) ?? '') : '';
        if ($text === '') {
            return ['text' => null, 'buy' => null, 'free' => null];
        }
        if (preg_match('/^(\d+)\+(\d+)$/', $text, $match)) {
            return ['text' => $text, 'buy' => (int) $match[1], 'free' => (int) $match[2]];
        }

        return ['text' => $text, 'buy' => null, 'free' => null];
    }

    /** Path parameter {id}; anything else is a 404. */
    public static function pathId(array $params, string $notFound, string $key = 'id'): int
    {
        $id = self::id($params[$key] ?? '');

        return $id ?? throw HttpException::notFound($notFound);
    }

    private static function clean(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return trim(preg_replace('/[ \t\r\n]+/u', ' ', $value) ?? '');
    }
}
