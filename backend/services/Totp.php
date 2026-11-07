<?php

declare(strict_types=1);

namespace App\Services;

/**
 * RFC 6238 TOTP (SHA-1, 30 seconds, 6 digits) for authenticator apps.
 * The secret is Base32. This class does not store or log it.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;
    private const DIGITS = 6;

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return self::codeAtStep($secret, intdiv($timestamp, self::PERIOD));
    }

    /** The counter step a code matches, within ±$window, or null. */
    public static function matchStep(string $secret, string $code, ?int $timestamp = null, int $window = 1): ?int
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $timestamp ??= time();
        $base = intdiv($timestamp, self::PERIOD);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $base + $offset;
            if ($step < 0) {
                continue;
            }
            if (hash_equals(self::codeAtStep($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function otpauthUri(string $secret, string $account, string $issuer = 'Yorilife'): string
    {
        $label = rawurlencode($issuer . ':' . $account);

        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function codeAtStep(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $counter = pack('J', $step);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $value = (
            ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= self::ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $encoded));
        if ($encoded === '') {
            throw new \InvalidArgumentException('Authenticator secret is empty.');
        }
        $bits = '';
        $length = strlen($encoded);
        for ($i = 0; $i < $length; $i++) {
            $pos = strpos(self::ALPHABET, $encoded[$i]);
            if ($pos === false) {
                throw new \InvalidArgumentException('Authenticator secret is not valid.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
