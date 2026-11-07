<?php

declare(strict_types=1);

namespace App\Services;

/**
 * RFC 6238 TOTP (SHA-1, 30-second step, 6 digits) and one-time recovery codes.
 * The authenticator secret is stored for verification and is never written to
 * the audit log. Recovery codes are stored only as SHA-256 hashes.
 */
final class Totp
{
    private const STEP = 30;
    private const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function uri(string $secret, string $email, string $issuer = 'Yorilife'): string
    {
        $label = rawurlencode($issuer . ':' . $email);

        return 'otpauth://totp/' . $label
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function code(string $secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), self::STEP);

        return self::codeForCounter($secret, $counter);
    }

    public static function verify(string $secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }
        $counter = intdiv($timestamp ?? time(), self::STEP);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::codeForCounter($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function recoveryCode(): string
    {
        $raw = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < 8; $i++) {
            $raw .= self::ALPHABET[random_int(0, $max)];
        }

        return substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }

    public static function hashRecovery(string $code): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return hash('sha256', $normalized);
    }

    public static function codeForCounter(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '000000';
        }
        $high = ($counter >> 32) & 0xFFFFFFFF;
        $low = $counter & 0xFFFFFFFF;
        $bin = pack('N2', $high, $low);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $binary, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= $alphabet[bindec($chunk)];
        }

        return $out;
    }

    private static function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');
        $bits = '';
        $length = strlen($secret);
        for ($i = 0; $i < $length; $i++) {
            $pos = strpos($alphabet, $secret[$i]);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
