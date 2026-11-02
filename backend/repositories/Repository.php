<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use App\Utils\HttpException;
use PDO;

/**
 * Shared helpers for the business-module repositories. Every query uses
 * prepared statements; table and column names come only from code, never
 * from the request.
 */
abstract class Repository
{
    protected function pdo(): PDO
    {
        return Database::connection();
    }

    /** @return array<string, mixed>|null */
    protected function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    protected function many(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    protected function exists(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $fields column => value */
    protected function insertRow(string $table, array $fields): int
    {
        $columns = implode(', ', array_keys($fields));
        $marks = implode(', ', array_fill(0, count($fields), '?'));
        $this->pdo()->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$marks})")->execute(array_values($fields));

        return (int) $this->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $fields column => value */
    protected function updateRow(string $table, int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($fields)));
        $this->pdo()->prepare("UPDATE {$table} SET {$assignments} WHERE id = ?")->execute([...array_values($fields), $id]);
    }

    /**
     * Run a write. A duplicate-key error (1062) becomes a 422. $duplicates maps
     * a fragment of the index name to field errors.
     *
     * @param array<string, array<string, string>> $duplicates
     */
    protected function run(callable $work, array $duplicates = []): mixed
    {
        try {
            return $work();
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $detail = $e->getMessage();
            foreach ($duplicates as $needle => $fields) {
                if (str_contains($detail, $needle)) {
                    throw HttpException::validation($fields);
                }
            }
            throw HttpException::validation(['record' => 'This record conflicts with one that already exists.']);
        }
    }

    /** Run $work in a transaction (rolled back if it throws). */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
