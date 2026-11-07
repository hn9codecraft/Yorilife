<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AuditLogger;

use InvalidArgumentException;

/**
 * Import history, row errors, per-record change snapshots and the audit log.
 * Also reads and restores raw Sales Office rows for backup and rollback.
 * Table names come only from the whitelist below, never from the request.
 */
final class ImportRepository extends Repository
{
    /** Sales Office tables an import may touch. */
    public const RECORD_TABLES = ['locations', 'location_headquarters', 'managers', 'headquarters', 'stockists'];

    /** Columns that are not compared or restored. */
    private const IGNORED_COLUMNS = ['created_at', 'updated_at', 'active_code'];

    private const IMPORT_COLUMNS = 'i.id, i.entity_type, i.original_filename, i.stored_filename, i.file_size, i.file_sha256, i.backup_sha256,
        i.status, i.total_rows, i.created_count, i.updated_count, i.unchanged_count, i.error_count, i.preview,
        i.uploaded_by, i.uploaded_at, i.imported_by, i.imported_at, i.rolled_back_by, i.rolled_back_at, i.rollback_summary,
        up.name AS uploaded_by_name, im.name AS imported_by_name, rb.name AS rolled_back_by_name';

    private const IMPORT_JOINS = 'FROM imports i
        LEFT JOIN users up ON up.id = i.uploaded_by
        LEFT JOIN users im ON im.id = i.imported_by
        LEFT JOIN users rb ON rb.id = i.rolled_back_by';

    /** @param array<string, mixed> $fields */
    public function createImport(array $fields): int
    {
        return $this->insertRow('imports', $fields);
    }

    /** @param array<string, mixed> $fields */
    public function updateImport(int $id, array $fields): void
    {
        $this->updateRow('imports', $id, $fields);
    }

    public function deleteImport(int $id): void
    {
        $this->pdo()->prepare('DELETE FROM import_errors WHERE import_id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM imports WHERE id = ?')->execute([$id]);
    }

    /** @return array<string, mixed>|null */
    public function findImport(int $id, bool $lock = false): ?array
    {
        if ($lock) {
            $this->one('SELECT id FROM imports WHERE id = ? FOR UPDATE', [$id]);
        }

        return $this->one('SELECT ' . self::IMPORT_COLUMNS . ' ' . self::IMPORT_JOINS . ' WHERE i.id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> newest first */
    public function imports(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        return $this->many('SELECT ' . self::IMPORT_COLUMNS . ' ' . self::IMPORT_JOINS . " ORDER BY i.id DESC LIMIT {$limit}");
    }

    /** @param list<array{line: int, field: string, message: string, row: list<string>|null}> $errors */
    public function replaceErrors(int $importId, array $errors): void
    {
        $this->pdo()->prepare('DELETE FROM import_errors WHERE import_id = ?')->execute([$importId]);
        $insert = $this->pdo()->prepare('INSERT INTO import_errors (import_id, line_number, data_type, field, message, row_data) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($errors as $error) {
            $insert->execute([
                $importId,
                $error['line'],
                $error['type'] ?? null,
                mb_substr($error['field'], 0, 100),
                mb_substr($error['message'], 0, 500),
                $error['row'] === null ? null : json_encode($error['row'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function errors(int $importId): array
    {
        return $this->many('SELECT line_number, data_type, field, message, row_data FROM import_errors WHERE import_id = ? ORDER BY line_number, id', [$importId]);
    }

    /** @param array<string, mixed> $fields */
    public function addChange(array $fields): int
    {
        return $this->insertRow('import_changes', $fields);
    }

    /** @return list<array<string, mixed>> in the order they were applied */
    public function changes(int $importId): array
    {
        return $this->many('SELECT * FROM import_changes WHERE import_id = ? ORDER BY id', [$importId]);
    }

    /**
     * @param list<int> $importIds
     * @return array<int, int> import id => records still pending rollback (only ids with some)
     */
    public function pendingChangeCounts(array $importIds): array
    {
        if ($importIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($importIds), '?'));
        $out = [];
        foreach ($this->many("SELECT import_id, COUNT(*) AS n FROM import_changes WHERE rollback_status = 'pending' AND import_id IN ($marks) GROUP BY import_id", array_values($importIds)) as $row) {
            $out[(int) $row['import_id']] = (int) $row['n'];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $items */
    public function addRollback(int $importId, int $userId, array $plan, array $items): void
    {
        $this->pdo()->prepare('INSERT INTO import_rollbacks (import_id, user_id, restored, deleted, skipped, not_selected, items, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$importId, $userId, $plan['restored'], $plan['deleted'], $plan['skipped'], $plan['notSelected'],
                json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @param list<int> $importIds
     * @return array<int, list<array<string, mixed>>> import id => rollback runs, oldest first
     */
    public function rollbacks(array $importIds): array
    {
        if ($importIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($importIds), '?'));
        $out = [];
        foreach ($this->many("SELECT r.*, u.name AS user_name FROM import_rollbacks r LEFT JOIN users u ON u.id = r.user_id WHERE r.import_id IN ($marks) ORDER BY r.id", array_values($importIds)) as $row) {
            $out[(int) $row['import_id']][] = $row;
        }

        return $out;
    }

    public function markChange(int $changeId, string $status, ?string $note): void
    {
        $this->pdo()->prepare('UPDATE import_changes SET rollback_status = ?, rollback_note = ? WHERE id = ?')
            ->execute([$status, $note === null ? null : mb_substr($note, 0, 500), $changeId]);
    }

    /** @param array<string, mixed> $details */
    public function audit(int $importId, string $action, ?int $userId, ?string $ip, array $details = []): void
    {
        $this->pdo()->prepare('INSERT INTO import_audit_logs (import_id, action, user_id, ip_address, details) VALUES (?, ?, ?, ?, ?)')
            ->execute([
                $importId,
                $action,
                $userId,
                $ip === null ? null : substr($ip, 0, 45),
                $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        AuditLogger::record([
            'module' => 'import',
            'action' => $action,
            'actorId' => $userId,
            'targetType' => 'import',
            'targetId' => $importId,
            'targetLabel' => 'Import #' . $importId,
            'details' => $details === [] ? null : $details,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function auditLog(int $importId): array
    {
        return $this->many(
            'SELECT a.action, a.user_id, u.name AS user_name, a.ip_address, a.details, a.created_at
             FROM import_audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.import_id = ? ORDER BY a.id',
            [$importId]
        );
    }

    public function savepoint(string $name): void
    {
        $this->pdo()->exec("SAVEPOINT {$name}");
    }

    public function rollbackTo(string $name): void
    {
        $this->pdo()->exec("ROLLBACK TO SAVEPOINT {$name}");
    }

    public function release(string $name): void
    {
        $this->pdo()->exec("RELEASE SAVEPOINT {$name}");
    }

    /**
     * Raw row as text values, without timestamps. A manager also carries its
     * sorted state (location) ids under "_states".
     *
     * @return array<string, string|null>|null
     */
    public function snapshot(string $table, int $id): ?array
    {
        self::assertTable($table);
        $row = $this->one("SELECT * FROM {$table} WHERE id = ?", [$id]);
        if ($row === null) {
            return null;
        }
        $clean = [];
        foreach ($row as $column => $value) {
            if (in_array($column, self::IGNORED_COLUMNS, true)) {
                continue;
            }
            $clean[$column] = $value === null ? null : (string) $value;
        }
        if ($table === 'managers') {
            $clean['_states'] = implode('|', $this->managerStateIds($id));
        }

        return $clean;
    }

    /** @return list<int> */
    public function managerStateIds(int $managerId): array
    {
        $ids = array_map(
            static fn (array $row): int => (int) $row['location_id'],
            $this->many('SELECT location_id FROM manager_states WHERE manager_id = ?', [$managerId]),
        );
        sort($ids);

        return $ids;
    }

    /** Write a snapshot back onto the row (all columns except the id). */
    public function restore(string $table, int $id, array $snapshot): void
    {
        self::assertTable($table);
        $fields = [];
        foreach ($snapshot as $column => $value) {
            if ($column === 'id' || str_starts_with((string) $column, '_') || in_array($column, self::IGNORED_COLUMNS, true)) {
                continue;
            }
            if (preg_match('/^[a-z_]+$/', (string) $column) !== 1) {
                throw new InvalidArgumentException('Unexpected column in snapshot.');
            }
            $fields[$column] = $value;
        }
        $this->updateRow($table, $id, $fields);
        if ($table === 'managers' && array_key_exists('_states', $snapshot)) {
            $this->pdo()->prepare('DELETE FROM manager_states WHERE manager_id = ?')->execute([$id]);
            $insert = $this->pdo()->prepare('INSERT INTO manager_states (manager_id, location_id) VALUES (?, ?)');
            foreach (array_filter(explode('|', (string) $snapshot['_states']), static fn (string $v): bool => $v !== '') as $locationId) {
                $insert->execute([$id, (int) $locationId]);
            }
        }
    }

    public function deleteRecord(string $table, int $id): void
    {
        self::assertTable($table);
        if ($table === 'managers') {
            $this->pdo()->prepare('DELETE FROM manager_states WHERE manager_id = ?')->execute([$id]);
        }
        $this->pdo()->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
    }

    /**
     * Records that still point at this row, ignoring rows listed in $ignore
     * (records the same rollback deletes first).
     *
     * @param array<string, list<int>> $ignore table => ids
     * @return list<string> human-readable reasons
     */
    public function dependents(string $table, int $id, array $ignore = []): array
    {
        self::assertTable($table);
        $checks = match ($table) {
            'locations' => [
                ['managers', 'SELECT manager_id AS id FROM manager_states WHERE location_id = ?', 'a manager has this state'],
                ['headquarters', 'SELECT id FROM headquarters WHERE location_id = ?', 'a headquarters uses this state'],
                ['location_headquarters', 'SELECT id FROM location_headquarters WHERE location_id = ?', 'a location headquarters belongs to this state'],
            ],
            'location_headquarters' => [
                ['headquarters', 'SELECT id FROM headquarters WHERE location_headquarters_id = ?', 'a headquarters office is linked to it'],
            ],
            'managers' => [
                ['managers', 'SELECT id FROM managers WHERE parent_manager_id = ?', 'a manager reports to this manager'],
                ['headquarters', 'SELECT id FROM headquarters WHERE parent_manager_id = ?', 'a headquarters belongs to this manager'],
            ],
            'headquarters' => [
                ['stockists', 'SELECT id FROM stockists WHERE headquarters_id = ?', 'a stockist belongs to this headquarters'],
                ['sales_entries', 'SELECT id FROM sales_entries WHERE headquarters_id = ?', 'sales entries exist for this headquarters'],
            ],
            'stockists' => [
                ['sales_entries', 'SELECT id FROM sales_entries WHERE stockist_id = ?', 'sales entries exist for this stockist'],
            ],
        };
        $reasons = [];
        foreach ($checks as [$childTable, $sql, $reason]) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $this->many($sql, [$id]));
            $remaining = array_diff($ids, $ignore[$childTable] ?? []);
            if ($remaining !== []) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /**
     * Full copy of the Sales Office data before an import: locations, location
     * headquarters, the manager hierarchy with manager_states, headquarters and
     * stockists (with their links).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    /** @return array<string, array<int, string>> table => id => code (or name), for the readable backup. */
    public function recordCodes(): array
    {
        $codes = [];
        foreach (self::RECORD_TABLES as $table) {
            foreach ($this->many("SELECT id, COALESCE(code, name) AS code FROM {$table}") as $row) {
                $codes[$table][(int) $row['id']] = (string) $row['code'];
            }
        }

        return $codes;
    }

    public function salesOfficeBackup(): array
    {
        return [
            'locations' => $this->many('SELECT * FROM locations ORDER BY id'),
            'location_headquarters' => $this->many('SELECT * FROM location_headquarters ORDER BY id'),
            'managers' => $this->many('SELECT * FROM managers ORDER BY level DESC, id'),
            'manager_states' => $this->many('SELECT * FROM manager_states ORDER BY manager_id, location_id'),
            'headquarters' => $this->many('SELECT * FROM headquarters ORDER BY id'),
            'stockists' => $this->many('SELECT * FROM stockists ORDER BY id'),
        ];
    }

    private static function assertTable(string $table): void
    {
        if (!in_array($table, self::RECORD_TABLES, true)) {
            throw new InvalidArgumentException('Unknown record table.');
        }
    }
}
