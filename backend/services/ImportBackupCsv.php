<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Builds the readable backup CSV a client downloads from Import History (opens
 * in Excel). It lists only the existing records this import replaced (updated),
 * with each field's previous value next to its new value, so a mistake can be
 * spotted before rolling back. Created records are not listed. Rollback never
 * reads this file; it uses backup.json and import_changes.
 */
final class ImportBackupCsv
{
    /** Fields compared for each replaced record, in sheet order. */
    public const FIELDS = ['Name', 'Parent Code', 'State List', 'Address', 'Pincode', 'Phone Number', 'Email'];

    public const NO_REPLACEMENTS = 'No records were replaced in this import. It only added new records, so there is nothing to compare.';

    /** Stored field for each readable column. Parent Code and State List are resolved to codes. */
    private const STORED = ['Code' => 'code', 'Name' => 'name', 'Address' => 'address', 'Pincode' => 'pincode', 'Phone Number' => 'phone', 'Email' => 'email'];

    /** Data Type => [stored parent field, table the parent lives in]. */
    private const PARENTS = [
        'location-headquarters' => ['location_id', 'locations'],
        'manager-2' => ['parent_manager_id', 'managers'],
        'manager-1' => ['parent_manager_id', 'managers'],
        'headquarters' => ['parent_manager_id', 'managers'],
        'stockist' => ['headquarters_id', 'headquarters'],
    ];

    /** @return list<string> Data Type, Code, Previous/New pairs, Changed Fields, Notes */
    public static function columns(): array
    {
        $columns = ['Data Type', 'Code'];
        foreach (self::FIELDS as $field) {
            $columns[] = 'Previous ' . $field;
            $columns[] = 'New ' . $field;
        }

        return [...$columns, 'Changed Fields', 'Notes'];
    }

    /**
     * @param list<array<string, mixed>> $changes import_changes rows
     * @param array<string, list<array<string, mixed>>> $backup tables saved in backup.json
     * @param array<string, array<int, string>> $currentCodes table => id => code, read now
     */
    public static function build(array $changes, array $backup, array $currentCodes): string
    {
        $codes = self::codes($changes, $backup, $currentCodes);
        $rows = [];
        foreach ($changes as $change) {
            if ($change['action'] !== 'updated' || $change['before_data'] === null) {
                continue;
            }
            $after = json_decode((string) $change['after_data'], true) ?: [];
            $before = json_decode((string) $change['before_data'], true) ?: [];
            $type = self::type($change, $after);
            $new = self::values($type, $after, $codes);
            $old = self::values($type, $before, $codes);
            $row = [ImportTypes::DATA_TYPE_LABELS[$type] ?? 'Unknown', $new['Code'] !== '' ? $new['Code'] : (string) $change['record_code']];
            $changed = [];
            foreach (self::FIELDS as $field) {
                $row[] = PiiMask::field($field, (string) ($old[$field] ?? ''));
                $row[] = PiiMask::field($field, (string) ($new[$field] ?? ''));
                if (($old[$field] ?? '') !== ($new[$field] ?? '')) {
                    $changed[] = $field;
                }
            }
            if ($changed === []) {
                // Only fields the client does not see changed (e.g. a headquarters copy of
                // its Location HQ address); the Location HQ row already shows the change.
                continue;
            }
            $row[] = implode(', ', $changed);
            $row[] = self::note($change);
            $rows[] = $row;
        }
        $columns = self::columns();
        if ($rows === []) {
            $rows[] = [...array_fill(0, count($columns) - 1, ''), self::NO_REPLACEMENTS];
        }

        return ImportCsv::document($columns, $rows, true);
    }
    /** @return array<string, string> readable column => value, only the columns this Data Type uses */
    private static function values(string $type, array $data, array $codes): array
    {
        $used = ['Code', 'Name', ...array_keys(ImportTypes::UNIFIED_MAP[$type] ?? [])];
        $out = [];
        foreach (self::STORED as $column => $field) {
            if (in_array($column, $used, true)) {
                $out[$column] = trim((string) ($data[$field] ?? ''));
            }
        }
        if (isset(self::PARENTS[$type])) {
            [$field, $table] = self::PARENTS[$type];
            $id = (int) ($data[$field] ?? 0);
            $out['Parent Code'] = $id > 0 ? ($codes[$table][$id] ?? '') : '';
        }
        if (in_array('State List', $used, true)) {
            $states = array_filter(explode('|', (string) ($data['_states'] ?? '')), static fn (string $id): bool => $id !== '');
            $out['State List'] = implode('|', array_map(static fn (string $id): string => $codes['locations'][(int) $id] ?? '', $states));
        }

        return $out;
    }

    private static function type(array $change, array $after): string
    {
        if (!empty($change['data_type'])) {
            return (string) $change['data_type'];
        }

        return match ((string) $change['record_table']) {
            'locations' => 'location',
            'location_headquarters' => 'location-headquarters',
            'managers' => 'manager-' . (int) ($after['level'] ?? 0),
            'headquarters' => 'headquarters',
            default => 'stockist',
        };
    }

    private static function note(array $change): string
    {
        return match ((string) ($change['rollback_status'] ?? 'pending')) {
            'restored' => 'Rolled back: the previous values were put back.',
                        'skipped' => 'Kept when rolled back. ' . (string) ($change['rollback_note'] ?? ''),
            default => '',
        };
    }

    /** @return array<string, array<int, string>> table => id => code (or name when a record has no code) */
    private static function codes(array $changes, array $backup, array $currentCodes): array
    {
        $codes = [];
        foreach ($backup as $table => $records) {
            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record['id'])) {
                    $codes[$table][(int) $record['id']] = (string) ($record['code'] ?? $record['name'] ?? '');
                }
            }
        }
        foreach ($changes as $change) {
            $after = json_decode((string) $change['after_data'], true) ?: [];
            $codes[(string) $change['record_table']][(int) $change['record_id']] = (string) ($after['code'] ?? $change['record_code'] ?? '');
        }
        foreach ($currentCodes as $table => $map) {
            foreach ($map as $id => $code) {
                $codes[$table][$id] ??= $code;
            }
        }

        return $codes;
    }
}