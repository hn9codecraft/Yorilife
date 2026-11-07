<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Masks phone numbers and email addresses in CSV exports.
 * Screen views for authorised admins stay unmasked.
 */
final class PiiMask
{
    public static function email(string $value): string
    {
        $value = trim($value);
        $at = strrpos($value, '@');
        if ($at === false || $at === 0 || $at === strlen($value) - 1) {
            return '***';
        }
        $local = substr($value, 0, $at);
        $domain = substr($value, $at + 1);
        $first = function_exists('mb_substr') ? mb_substr($local, 0, 1) : substr($local, 0, 1);

        return $first . '***@' . $domain;
    }

    public static function phone(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if ($digits === '') {
            return $value;
        }
        if (strlen($digits) < 8) {
            return preg_replace('/\d/', '*', $value) ?? '****';
        }
        $hide = strlen($digits) - 4;
        $seen = 0;
        $masked = preg_replace_callback('/\d/', static function (array $match) use (&$seen, $hide): string {
            $seen++;

            return $seen <= $hide ? '*' : $match[0];
        }, $value);

        return $masked ?? '****';
    }

    /** Email or phone, whichever the text looks like. */
    public static function contact(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '@')) {
            return self::email($value);
        }
        if (preg_match('/\d/', $value) === 1) {
            return self::phone($value);
        }

        return $value;
    }

    /** Mask emails and long phone-like numbers inside free text. Short codes stay. */
    public static function text(string $value): string
    {
        $value = preg_replace_callback(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            static fn (array $match): string => self::email($match[0]),
            $value
        ) ?? $value;

        return preg_replace_callback(
            '/\+?\d[\d\s().-]{6,}\d/',
            static function (array $match): string {
                $digits = preg_replace('/\D/', '', $match[0]) ?? '';

                return strlen($digits) >= 8 ? self::phone($match[0]) : $match[0];
            },
            $value
        ) ?? $value;
    }

    public static function field(string $field, string $value): string
    {
        if ($value === '') {
            return '';
        }
        $name = strtolower($field);
        if (str_contains($name, 'email')) {
            return self::email($value);
        }
        if (str_contains($name, 'phone') || str_contains($name, 'mobile')) {
            return self::phone($value);
        }

        return self::text($value);
    }

    /**
     * @param list<string|int> $header
     * @param list<list<mixed>> $rows
     * @return list<list<mixed>>
     */
    public static function maskTable(array $header, array $rows): array
    {
        $indexes = [];
        foreach ($header as $index => $name) {
            $label = strtolower((string) $name);
            if (str_contains($label, 'email') || str_contains($label, 'phone') || str_contains($label, 'mobile')) {
                $indexes[] = (int) $index;
            }
        }
        if ($indexes === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($indexes as $index) {
                if (!isset($row[$index]) || !is_string($row[$index]) || $row[$index] === '') {
                    continue;
                }
                $row[$index] = self::field((string) $header[$index], $row[$index]);
            }
        }
        unset($row);

        return $rows;
    }

    public static function maskMixed(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::text($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::maskMixed($item);
        }

        return $out;
    }
}
