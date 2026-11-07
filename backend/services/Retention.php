<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\HttpException;

/** How old a log row must be before it can be archived. Rows are never deleted. */
final class Retention
{
    /** @param array<string, mixed> $input */
    public static function days(array $input, string $settingKey): int
    {
        if (!array_key_exists('olderThanDays', $input)) {
            return SecurityPolicy::int($settingKey, 365, 30, 3650);
        }
        $raw = $input['olderThanDays'];
        if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
            throw HttpException::validation(['olderThanDays' => 'Enter a whole number of days.']);
        }
        $days = (int) $raw;
        if ($days < 30 || $days > 3650) {
            throw HttpException::validation(['olderThanDays' => 'Archive window must be between 30 and 3650 days.']);
        }

        return $days;
    }

    public static function cutoff(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    }
}
