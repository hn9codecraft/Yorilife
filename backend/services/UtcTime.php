<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Announcement times: stored as UTC DATETIME, sent to clients as ISO 8601 with
 * an offset, and typed in by admins in India time.
 */
final class UtcTime
{
    public const INDIA = 'Asia/Kolkata';

    /** Test hook: a fixed "now" (UTC "Y-m-d H:i:s"), never set in production. */
    public static ?string $fixedNow = null;

    public static function now(): string
    {
        return self::$fixedNow ?? gmdate('Y-m-d H:i:s');
    }

    public static function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Admin input -> UTC "Y-m-d H:i:s". Accepts "YYYY-MM-DDTHH:MM" (India time,
     * from a datetime-local field) or a full ISO 8601 value with an offset.
     */
    public static function parse(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $value)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i' . (strlen($value) > 16 ? ':s' : ''), str_replace('T', ' ', $value), new DateTimeZone(self::INDIA));
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            try {
                $date = new DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        } else {
            return null;
        }
        if ($date === false) {
            return null;
        }
        $year = (int) $date->format('Y');
        if ($year < 2000 || $year > 2100) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Year in India time, for reference numbers (ANN-2026-0012). */
    public static function indiaYear(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::INDIA))->format('Y');
    }

    public static function indiaLabel(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }

        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::INDIA))->format('d M Y, h:i A') . ' IST';
    }
}