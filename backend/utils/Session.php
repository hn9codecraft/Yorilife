<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Hardened wrapper around PHP's native session (server-side storage, the
 * browser only holds an opaque HttpOnly cookie). Sessions are started lazily:
 * requests without the cookie never create one.
 */
final class Session
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /** @param array<string, mixed> $config config/auth.php "session" section */
    public static function configure(array $config): void
    {
        self::$config = $config;

        if (!is_dir($config['save_path'])) {
            mkdir($config['save_path'], 0700, true);
        }

        ini_set('session.save_path', $config['save_path']);
        ini_set('session.use_strict_mode', '1');      // reject unknown/forged session IDs
        ini_set('session.use_only_cookies', '1');     // never accept IDs from the URL
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', 'nocache');
        ini_set('session.gc_maxlifetime', (string) ($config['absolute_lifetime_hours'] * 3600));
        // 32 chars × 5 bits = 160 random bits; [0-9a-v] needs no cookie encoding.
        ini_set('session.sid_bits_per_character', '5');

        session_name($config['cookie_name']);
        session_set_cookie_params([
            'lifetime' => 0,       // browser-session cookie; expiry is enforced server-side
            'path' => '/',
            'secure' => $config['secure'],
            'httponly' => true,    // not readable from JavaScript
            'samesite' => $config['same_site'],
        ]);
    }

    public static function config(string $key): mixed
    {
        return self::$config[$key];
    }

    public static function hasCookie(): bool
    {
        return isset($_COOKIE[self::$config['cookie_name']]) && is_string($_COOKIE[self::$config['cookie_name']]);
    }

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /** New session ID after sign-in (prevents session fixation); old session data is deleted. */
    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    /** Release the session lock early so parallel requests from the same user aren't serialised. */
    public static function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** Delete server-side session data and expire the cookie. */
    public static function destroy(): void
    {
        if (!self::hasCookie() && session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        self::start();
        $_SESSION = [];
        session_destroy();

        setcookie(self::$config['cookie_name'], '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => self::$config['secure'],
            'httponly' => true,
            'samesite' => self::$config['same_site'],
        ]);
    }
}
