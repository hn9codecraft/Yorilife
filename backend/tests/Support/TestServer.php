<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** Runs the real front controller under PHP's built-in server for feature tests. */
final class TestServer
{
    public const BASE_URL = 'http://127.0.0.1:8099';

    /** @var resource|null */
    private static $process = null;

    public static function sessionPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/test-sessions';
    }

    public static function start(string $database): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_dir(self::sessionPath())) {
            mkdir(self::sessionPath(), 0700, true);
        }
        array_map('unlink', glob(self::sessionPath() . '/sess_*') ?: []);

        $env = getenv() + [];
        $env['DB_DATABASE'] = $database;
        $env['APP_ENV'] = 'testing';
        $env['APP_DEBUG'] = 'false';
        $env['APP_URL'] = self::BASE_URL;
        $env['CORS_ALLOWED_ORIGINS'] = 'http://localhost:5173';
        $env['SESSION_SECURE_COOKIE'] = 'false';
        $env['SESSION_SAVE_PATH'] = self::sessionPath();
        $env['IMPORT_STORAGE_PATH'] = $root . '/storage/test-imports';

        self::$process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:8099', '-t', $root . '/public', $root . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::logFile(), 'a'], 2 => ['file', self::logFile(), 'a']],
            $pipes,
            $root,
            $env,
        );
        if (!is_resource(self::$process)) {
            throw new RuntimeException('Could not start the test API server.');
        }
        register_shutdown_function([self::class, 'stop']);

        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents(self::BASE_URL . '/api/health') !== false) {
                return;
            }
            usleep(100_000);
        }
        throw new RuntimeException('Test API server did not start. See ' . self::logFile());
    }

    public static function stop(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    private static function logFile(): string
    {
        return dirname(__DIR__, 2) . '/storage/test-server.log';
    }
}
