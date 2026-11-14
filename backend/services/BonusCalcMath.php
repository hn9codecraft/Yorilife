<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Bonus Calc figures. Quantities and money stay decimal strings (two places)
 * so the stored amount does not depend on binary floats.
 *
 * Total product = opening quantity + new purchase.
 * Bonus amount = product sales × net rate, rounded half up to two places.
 * A missing net rate counts as zero.
 */
final class BonusCalcMath
{
    public const DEFAULT_YEAR = '2026-27';

    /** @var array<int, string> */
    public const MONTHS = [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    ];

    /** @var array<int, string> */
    public const MONTH_SHORT = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ];

    /** April–March label such as 2026-27. */
    public static function financialYear(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})$/', trim($value), $match) !== 1) {
            return null;
        }
        $start = (int) $match[1];
        $end = (int) $match[2];
        if ($start < 2000 || $start > 2100 || $end !== ($start + 1) % 100) {
            return null;
        }

        return sprintf('%04d-%02d', $start, $end);
    }

    /** Calendar month 1–12. */
    public static function month(mixed $value): ?int
    {
        if (is_int($value) && $value >= 1 && $value <= 12) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:[1-9]|1[0-2])$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Non-negative quantity with at most two decimal places.
     * Blank becomes "0.00".
     *
     * @return array{0: ?string, 1: ?string} [value, error]
     */
    public static function quantity(mixed $value, string $label): array
    {
        if ($value === null || $value === '') {
            return ['0.00', null];
        }
        if (is_int($value)) {
            if ($value < 0) {
                return [null, "{$label} cannot be negative."];
            }
            $cents = (string) $value . '00';

            return self::tooLarge($cents) ? [null, "{$label} is too large."] : [self::fromCents($cents), null];
        }
        if (is_float($value)) {
            if ($value < 0) {
                return [null, "{$label} cannot be negative."];
            }
            $cents = (int) round($value * 100);
            if (abs($value * 100 - $cents) > 0.000001) {
                return [null, "{$label} must be a number with at most two decimal places."];
            }

            return self::tooLarge((string) $cents) ? [null, "{$label} is too large."] : [self::fromCents((string) $cents), null];
        }
        if (!is_string($value)) {
            return [null, "{$label} must be a number with at most two decimal places."];
        }
        $text = trim($value);
        if ($text === '') {
            return ['0.00', null];
        }
        if (str_starts_with($text, '-')) {
            return [null, "{$label} cannot be negative."];
        }
        if (preg_match('/^\d+(\.\d{1,2})?$/', $text) !== 1) {
            return [null, "{$label} must be a number with at most two decimal places."];
        }
        $cents = self::cents($text);

        return self::tooLarge($cents) ? [null, "{$label} is too large."] : [self::fromCents($cents), null];
    }

    /** Opening + new purchase. Both values are already normalised. */
    public static function totalProduct(string $opening, string $newPurchase): string
    {
        return self::fromCents(self::stringAdd(self::cents($opening), self::cents($newPurchase)));
    }

    /**
     * Product sales × net rate, half up to two places.
     * A missing rate counts as zero.
     *
     * @return array{0: ?string, 1: ?string} [amount, error]
     */
    public static function bonusAmount(string $productSales, ?string $netRate): array
    {
        if ($netRate === null || $netRate === '') {
            return ['0.00', null];
        }
        [$rate, $error] = self::quantity($netRate, 'Net rate');
        if ($error !== null || $rate === null) {
            return [null, $error ?? 'Net rate must be a number with at most two decimal places.'];
        }
        $product = self::stringMul(self::cents($productSales), self::cents($rate));
        $cents = self::roundDiv100($product);
        if (self::exceeds($cents, 16)) {
            return [null, 'Bonus amount is too large.'];
        }

        return [self::fromCents($cents), null];
    }

    /** @param list<?string> $amounts */
    public static function sum(array $amounts): string
    {
        $total = '0';
        foreach ($amounts as $amount) {
            $total = self::stringAdd($total, self::cents(self::moneyString($amount)));
        }

        return self::fromCents($total);
    }

    /** Normalise a stored or summed decimal to two places. */
    public static function moneyString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }
        if (is_int($value)) {
            return self::fromCents($value . '00');
        }
        $text = trim((string) $value);
        if (preg_match('/^\d+(\.\d{1,2})?$/', $text) === 1) {
            return self::fromCents(self::cents($text));
        }
        if (preg_match('/^(\d+)\.(\d+)$/', $text, $match) === 1) {
            $keep = substr(str_pad($match[2], 2, '0'), 0, 2);
            $roundUp = isset($match[2][2]) && $match[2][2] >= '5';
            $cents = self::stringAdd($match[1] . $keep, $roundUp ? '1' : '0');

            return self::fromCents($cents);
        }

        return '0.00';
    }

    public static function cents(string $amount): string
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $match) !== 1) {
            return '0';
        }
        $frac = str_pad($match[2] ?? '', 2, '0');

        return ltrim($match[1] . $frac, '0') ?: '0';
    }

    public static function fromCents(string $cents): string
    {
        $digits = ltrim($cents, '0');
        if ($digits === '') {
            return '0.00';
        }
        if (strlen($digits) <= 2) {
            return '0.' . str_pad($digits, 2, '0', STR_PAD_LEFT);
        }

        return substr($digits, 0, -2) . '.' . substr($digits, -2);
    }

    /** DECIMAL(12,2) ceiling: 12 digits of cents. */
    private static function tooLarge(string $cents): bool
    {
        return self::exceeds($cents, 12);
    }

    private static function exceeds(string $cents, int $digits): bool
    {
        return strlen(ltrim($cents, '0') ?: '0') > $digits;
    }

    /** Half-up division by 100 of an integer string (the product of two cent values). */
    private static function roundDiv100(string $number): string
    {
        $adjusted = self::stringAdd($number, '50');
        if (strlen($adjusted) <= 2) {
            return '0';
        }

        return ltrim(substr($adjusted, 0, -2), '0') ?: '0';
    }

    private static function stringAdd(string $left, string $right): string
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        $carry = 0;
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        $out = '';
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry;
            if ($i >= 0) {
                $sum += (int) $left[$i];
                $i--;
            }
            if ($j >= 0) {
                $sum += (int) $right[$j];
                $j--;
            }
            $out = (string) ($sum % 10) . $out;
            $carry = intdiv($sum, 10);
        }

        return ltrim($out, '0') ?: '0';
    }

    private static function stringMul(string $left, string $right): string
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if ($left === '0' || $right === '0') {
            return '0';
        }
        $width = strlen($left) + strlen($right);
        $result = array_fill(0, $width, 0);
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $result[$i + $j + 1] += (int) $left[$i] * (int) $right[$j];
            }
        }
        for ($k = $width - 1; $k > 0; $k--) {
            $result[$k - 1] += intdiv($result[$k], 10);
            $result[$k] %= 10;
        }

        return ltrim(implode('', $result), '0') ?: '0';
    }
}
