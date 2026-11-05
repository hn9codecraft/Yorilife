<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ImportRepository;
use App\Utils\HttpException;
use Throwable;

/**
 * Undo a confirmed import, in one or more runs.
 *
 * Each run rolls back some or all of the records that are still pending; the
 * rest stay pending for a later run. A record is rolled back at most once:
 * restored, deleted and skipped records are never offered again. Every run is
 * kept in import_rollbacks for the history.
 *
 * Changes are undone newest first. An updated record goes back to how it was
 * before the import; a created record is deleted. A record that was changed
 * after the import, is gone, or (for a created record) now has other records
 * linked to it is skipped and reported instead. Everything runs in one
 * transaction; each record has its own savepoint so one failure skips only
 * that record.
 */
final class ImportRollbackService
{
    public function __construct(private readonly ImportRepository $imports = new ImportRepository())
    {
    }

    /** What a rollback would do now, without changing anything. */
    /** @param list<int>|null $changeIds see rollback() */
    public function preview(int $id, ?array $changeIds = null): array
    {
        $import = $this->requireRollbackable($id);
        $selected = $changeIds === null ? null : $this->checkSelection($id, $changeIds);
        $pdo = \App\Database\Database::connection();
        $pdo->beginTransaction();
        try {
            $plan = $this->execute($id, false, $selected);
        } finally {
            $pdo->rollBack();
        }

        return ['importId' => (string) $import['id'], 'status' => $import['status']] + $plan;
    }

    /**
     * @param array<string, mixed> $user
     * @param list<int>|null $changeIds the preview items (import_changes ids) to roll back; null means all.
     *        Unselected records are left as imported. Selected ones still go through every skip check.
     */
    public function rollback(int $id, array $user, string $ip, ?array $changeIds = null): array
    {
        $result = $this->imports->transaction(function () use ($id, $user, $changeIds): array {
            $locked = $this->imports->findImport($id, true);
            if ($locked === null) {
                throw HttpException::notFound('Import not found.');
            }
            $this->assertRollbackable($locked);
            $selected = $changeIds === null ? null : $this->checkSelection($id, $changeIds);
            $plan = $this->execute($id, true, $selected);
            $left = $this->imports->changes($id);
            $pending = count(array_filter($left, static fn (array $c): bool => $c['rollback_status'] === 'pending'));
            $skipped = count(array_filter($left, static fn (array $c): bool => $c['rollback_status'] === 'skipped'));
            $status = $pending === 0 && $skipped === 0 ? 'rolled_back' : 'partially_rolled_back';
            $done = array_values(array_filter($plan['items'], static fn (array $i): bool => $i['result'] !== 'not_selected'));
            $this->imports->addRollback($id, (int) $user['id'], $plan, $done);
            $this->imports->updateImport($id, [
                'status' => $status,
                'rolled_back_by' => (int) $user['id'],
                'rolled_back_at' => gmdate('Y-m-d H:i:s'),
                'rollback_summary' => json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);

            return $plan + ['status' => $status, 'pendingLeft' => $pending];
        });
        $this->imports->audit($id, 'rollback', (int) $user['id'], $ip, [
            'status' => $result['status'], 'restored' => $result['restored'],
            'deleted' => $result['deleted'], 'skipped' => $result['skipped'],
            'selection' => $result['notSelected'] > 0 ? 'partial' : 'all', 'notSelected' => $result['notSelected'],
            'records' => array_values(array_map(static fn (array $i): string => $i['code'] . ' ' . $i['result'], array_filter($result['items'], static fn (array $i): bool => $i['result'] !== 'not_selected'))),
        ]);

        return $result;
    }

    /**
     * @param list<mixed> $changeIds
     * @return array<int, true> change id => true
     */
    private function checkSelection(int $id, array $changeIds): array
    {
        $all = $this->imports->changes($id);
        $valid = array_column($all, 'id');
        $done = array_map('strval', array_column(array_filter($all, static fn (array $c): bool => $c['rollback_status'] !== 'pending'), 'id'));
        $selected = [];
        foreach ($changeIds as $changeId) {
            if (!is_int($changeId) && !(is_string($changeId) && ctype_digit($changeId))) {
                throw HttpException::validation(['changeIds' => 'Choose the records to roll back.']);
            }
            if (!in_array((string) $changeId, array_map('strval', $valid), true)) {
                throw HttpException::validation(['changeIds' => 'A selected record is not part of this import. Refresh and try again.']);
            }
            if (in_array((string) $changeId, $done, true)) {
                throw HttpException::validation(['changeIds' => 'A selected record was already rolled back. Refresh and try again.']);
            }
            $selected[(int) $changeId] = true;
        }
        if ($selected === []) {
            throw HttpException::validation(['changeIds' => 'Select at least one record to roll back.']);
        }

        return $selected;
    }

    /**
     * @param array<int, true>|null $selected change ids to roll back; null means all
     * @return array{items: list<array<string, mixed>>, restored: int, deleted: int, skipped: int, notSelected: int}
     */
    private function execute(int $id, bool $record, ?array $selected = null): array
    {
        // Only records not yet handled by an earlier rollback run.
        $changes = array_reverse(array_values(array_filter(
            $this->imports->changes($id),
            static fn (array $c): bool => ($c['rollback_status'] ?? 'pending') === 'pending',
        )));
        // Records this rollback deletes, so links between them do not block each other.
        // An unselected created record stays, so it is not planned (its parents stay too).
        $planned = [];
        foreach ($changes as $change) {
            if ($change['action'] === 'created' && ($selected === null || isset($selected[(int) $change['id']]))) {
                $planned[$change['record_table']][] = (int) $change['record_id'];
            }
        }

        $children = self::children($changes);
        $items = [];
        $counts = ['restored' => 0, 'deleted' => 0, 'skipped' => 0, 'notSelected' => 0];
        foreach ($changes as $change) {
            $table = (string) $change['record_table'];
            $recordId = (int) $change['record_id'];
            if ($selected !== null && !isset($selected[(int) $change['id']])) {
                // Left pending, so a later rollback can still pick it.
                $note = 'Not selected, so it was left as imported. It can be rolled back later.';
                $counts['notSelected']++;
                $items[] = self::item($change, 'not_selected', $note, $children);
                continue;
            }
            $after = json_decode((string) $change['after_data'], true);
            $before = $change['before_data'] === null ? null : json_decode((string) $change['before_data'], true);
            $outcome = 'skipped';
            $note = null;

            $this->imports->savepoint('rollback_item');
            try {
                $current = $this->imports->snapshot($table, $recordId);
                if ($current === null) {
                    $note = 'The record no longer exists.';
                } elseif ($current != $after) {
                    $note = 'The record was changed after the import, so it was left as it is.';
                } elseif ($change['action'] === 'updated') {
                    $this->imports->restore($table, $recordId, $before ?? []);
                    $outcome = 'restored';
                } else {
                    $reasons = $this->imports->dependents($table, $recordId, $planned);
                    if ($reasons !== []) {
                        $note = 'Not deleted: ' . implode('; ', $reasons) . '.';
                    } else {
                        $this->imports->deleteRecord($table, $recordId);
                        $outcome = 'deleted';
                    }
                }
                $this->imports->release('rollback_item');
            } catch (Throwable $e) {
                $this->imports->rollbackTo('rollback_item');
                error_log('[import rollback] ' . $table . '#' . $recordId . ': ' . $e->getMessage());
                $outcome = 'skipped';
                $note = 'Could not be undone: the database rejected the change.';
            }

            if ($outcome === 'skipped') {
                // A skipped created record stays, so its parents must stay too.
                if ($change['action'] === 'created') {
                    $planned[$table] = array_values(array_diff($planned[$table] ?? [], [$recordId]));
                }
            }
            $counts[$outcome]++;
            if ($record) {
                $this->imports->markChange((int) $change['id'], $outcome, $note);
            }
            $items[] = self::item($change, $outcome, $note, $children);
        }

        return ['items' => $items] + $counts;
    }

    /** Fields that link a record to its parent, by table: field => parent table. */
    private const LINKS = [
        'location_headquarters' => ['location_id' => 'locations'],
        'managers' => ['parent_manager_id' => 'managers', 'parent_headquarters_id' => 'headquarters', '_states' => 'locations'],
        'headquarters' => ['parent_manager_id' => 'managers', 'location_headquarters_id' => 'location_headquarters', 'location_id' => 'locations'],
        'stockists' => ['headquarters_id' => 'headquarters'],
    ];

    /**
     * For each created change: the created changes of the same import that link to it directly.
     *
     * @param list<array<string, mixed>> $changes
     * @return array<int, list<int>> change id => child change ids
     */
    private static function children(array $changes): array
    {
        $created = [];
        foreach ($changes as $change) {
            if ($change['action'] === 'created') {
                $created[$change['record_table'] . '#' . $change['record_id']] = (int) $change['id'];
            }
        }
        $children = [];
        foreach ($changes as $change) {
            if ($change['action'] !== 'created') {
                continue;
            }
            $after = json_decode((string) $change['after_data'], true) ?: [];
            foreach (self::LINKS[$change['record_table']] ?? [] as $field => $parentTable) {
                foreach (explode('|', (string) ($after[$field] ?? '')) as $parentId) {
                    $parent = $created[$parentTable . '#' . $parentId] ?? null;
                    if ($parent !== null && $parent !== (int) $change['id'] && !in_array((int) $change['id'], $children[$parent] ?? [], true)) {
                        $children[$parent][] = (int) $change['id'];
                    }
                }
            }
        }

        return $children;
    }

    /** @param array<string, mixed> $change */
    private static function item(array $change, string $result, ?string $note, array $children): array
    {
        return [
            'id' => (int) $change['id'],
            // Created records of this import that link to this one: deleting it needs them deleted too.
            'children' => $children[(int) $change['id']] ?? [],
            'line' => (int) $change['line_number'],
            'table' => (string) $change['record_table'],
            'code' => $change['record_code'],
            'type' => $change['data_type'] ?? null,
            'action' => $change['action'],
            'result' => $result,
            'note' => $note,
        ];
    }

    private function requireRollbackable(int $id): array
    {
        $import = $this->imports->findImport($id) ?? throw HttpException::notFound('Import not found.');
        $this->assertRollbackable($import);

        return $import;
    }

    /** A completed or partly rolled back import with records still pending. */
    private function assertRollbackable(array $import): void
    {
        if (!in_array($import['status'], ['completed', 'partially_rolled_back'], true)
            || $this->imports->pendingChangeCounts([(int) $import['id']]) === []) {
            throw new HttpException(409, 'rollback_not_allowed', 'Nothing is left to roll back in this import.');
        }
    }
}
