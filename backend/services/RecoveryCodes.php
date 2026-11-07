<?php

declare(strict_types=1);

namespace App\Services;

/** One-time recovery codes. Only the SHA-256 of the normalised code is stored. */
final class RecoveryCodes
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @return list<string> display form XXXXX-XXXXX */
    public static function generate(int $count = 8): array
    {
        $codes = [];
        $alphabetLength = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $count; $i++) {
            $raw = '';
            for ($n = 0; $n < 10; $n++) {
                $raw .= self::ALPHABET[random_int(0, $alphabetLength)];
            }
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }

        return $codes;
    }

    public static function hash(string $code): string
    {
        $normal = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return hash('sha256', $normal);
    }

    public static function looksLike(string $code): bool
    {
        $normal = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return strlen($normal) === 10;
    }
}
