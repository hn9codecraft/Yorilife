<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * Forward-only SQL migrations from database/migrations/*.sql, applied in file
 * name order and recorded in `schema_migrations`. There is no "down"/drop
 * step: migrations never remove tables or data.
 *
 * Note: MySQL commits DDL implicitly, so a file that fails half-way is not
 * rolled back — it is reported and left unrecorded so it can be fixed and re-run.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> all migration versions found on disk */
    public function available(): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        return array_map(static fn (string $file): string => basename($file, '.sql'), $files);
    }

    /** @return list<string> */
    public function applied(): array
    {
        // Read-only: a status check must not create anything.
        if ($this->pdo->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn() === false) {
            return [];
        }

        return $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
    public function pending(): array
    {
        return array_values(array_diff($this->available(), $this->applied()));
    }

    /**
     * @param callable(string): void $log
     * @return list<string> versions applied in this run
     */
    public function migrate(callable $log): array
    {
        $done = [];
        $this->ensureTable();

        foreach ($this->pending() as $version) {
            $log("Applying {$version} …");
            foreach (self::statements((string) file_get_contents("{$this->directory}/{$version}.sql")) as $sql) {
                try {
                    $this->pdo->exec($sql);
                } catch (\PDOException $e) {
                    throw new RuntimeException("Migration {$version} failed: {$e->getMessage()}", 0, $e);
                }
            }
            $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
            $done[] = $version;
        }

        return $done;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(255) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Split a migration file into statements on ";" at end of line.
     * Comment-only lines are dropped. (Migrations must not contain ";\n" inside strings.)
     *
     * @return list<string>
     */
    private static function statements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--'),
        );

        return array_values(array_filter(
            array_map('trim', preg_split('/;\s*$/m', implode("\n", $lines)) ?: []),
            static fn (string $statement): bool => $statement !== '',
        ));
    }
}
