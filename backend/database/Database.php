<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * Lazily creates a single shared PDO connection per request.
 * Repositories (Sprint 2+) receive this connection; nothing else should build its own PDO.
 */
final class Database
{
    private static ?PDO $connection = null;

    /** @var array<string, mixed> */
    private static array $config = [];

    /** @param array<string, mixed> $config Values from config/database.php */
    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$connection = null;
    }

    public static function isConfigured(): bool
    {
        return (self::$config['database'] ?? '') !== '' && (self::$config['username'] ?? '') !== '';
    }

    /** @throws \PDOException when the server is unreachable or credentials are wrong */
    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        if (!self::isConfigured()) {
            throw new RuntimeException('Database is not configured. Set DB_DATABASE and DB_USERNAME in .env.');
        }

        $c = self::$config;
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'],
            $c['port'],
            $c['database'],
            $c['charset']
        );

        self::$connection = new PDO($dsn, $c['username'], $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => $c['timeout'],
            // All DATETIME columns are UTC; the API returns ISO-8601 with "Z".
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ]);

        return self::$connection;
    }
}
