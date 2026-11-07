<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ImportRepository;
use App\Repositories\SalesOfficeRepository;
use App\Utils\Env;
use App\Utils\HttpException;
use Throwable;

/**
 * Excel import for the Sales Office lists (one workbook, every Data Type).
 *
 * 1. Upload: the file is checked, stored under storage/imports/{id}/ with its
 *    SHA-256, and every row is validated inside a transaction that is always
 *    rolled back (preview). Nothing is saved to the Sales Office tables.
 * 2. Confirm: a backup.json of the Sales Office data is written, then the
 *    valid rows are applied in one transaction. An existing code is updated,
 *    a new code is created. Every created or updated record is recorded with
 *    its before/after snapshot so the import can be rolled back.
 */
final class ImportService
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_UNIFIED_ROWS = 2000;

    public function __construct(
        private readonly ImportRepository $imports = new ImportRepository(),
        private readonly SalesOfficeRepository $office = new SalesOfficeRepository(),
    ) {
    }

    public static function storageRoot(): string
    {
        $configured = Env::get('IMPORT_STORAGE_PATH', '');
        $root = is_string($configured) && $configured !== '' ? $configured : dirname(__DIR__) . '/storage/imports';

        return rtrim(str_replace('\\', '/', $root), '/');
    }

    public static function directory(int $importId): string
    {
        return self::storageRoot() . '/' . $importId;
    }

    /**
     * @param array<string, mixed>|null $upload one entry of $_FILES
     * @param array<string, mixed> $user
     */
    public function upload(mixed $type, ?array $upload, array $user, string $ip): array
    {
        if ($type !== null && $type !== '' && $type !== ImportTypes::UNIFIED) {
            throw HttpException::validation(['type' => 'Only the Excel import (all data in one sheet) is available.']);
        }

        return $this->uploadUnified($upload, $user, $ip);
    }

    /** @param array<string, mixed> $user */
    public function confirm(int $id, array $user, string $ip): array
    {
        $import = $this->imports->findImport($id) ?? throw HttpException::notFound('Import not found.');
        if ($import['status'] !== 'previewed') {
            throw new HttpException(409, 'import_not_pending', 'This import has already been processed.');
        }
        if ($import['entity_type'] !== ImportTypes::UNIFIED) {
            throw new HttpException(409, 'import_not_pending', 'CSV imports are no longer supported. Upload the Excel file instead.');
        }
        $content = $this->storedFile($import);
        $sheet = $this->parseXlsx($content);
        $backupPath = self::directory($id) . '/backup.json';

        try {
            $result = $this->office->transaction(function () use ($id, $import, $backupPath, $user, $sheet): array {
                $locked = $this->imports->findImport($id, true);
                if ($locked === null || $locked['status'] !== 'previewed') {
                    throw new HttpException(409, 'import_not_pending', 'This import has already been processed.');
                }
                $backup = json_encode([
                    'importId' => $id,
                    'type' => $import['entity_type'],
                    'file' => $import['original_filename'],
                    'fileSha256' => $import['file_sha256'],
                    'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
                    'createdBy' => ['id' => (int) $user['id'], 'name' => $user['name'] ?? null],
                    'data' => $this->imports->salesOfficeBackup(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (file_put_contents($backupPath, $backup, LOCK_EX) !== strlen($backup)) {
                    throw new \RuntimeException('Could not write the backup file.');
                }
                $result = $this->runUnified($sheet, true, $id);
                $this->imports->replaceErrors($id, $result['errors']);
                $this->imports->updateImport($id, $this->countFields($result) + [
                    'status' => 'completed',
                    'backup_sha256' => hash('sha256', $backup),
                    'imported_by' => (int) $user['id'],
                    'imported_at' => gmdate('Y-m-d H:i:s'),
                ]);

                return $result;
            });
        } catch (Throwable $e) {
            if (is_file($backupPath) && ($this->imports->findImport($id)['status'] ?? '') === 'previewed') {
                @unlink($backupPath);
            }
            throw $e;
        }

        $this->imports->audit($id, 'import', (int) $user['id'], $ip, [
            'created' => $result['created'], 'updated' => $result['updated'],
            'unchanged' => $result['unchanged'], 'errors' => $result['invalid'],
        ]);

        return $this->detail($id);
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $rows = $this->imports->imports();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $pending = $this->imports->pendingChangeCounts($ids);
        $runs = $this->imports->rollbacks($ids);

        return array_map(fn (array $row): array => $this->present($row, $pending, $runs), $rows);
    }

    public function detail(int $id): array
    {
        $import = $this->imports->findImport($id) ?? throw HttpException::notFound('Import not found.');
        $out = $this->present($import, $this->imports->pendingChangeCounts([$id]), $this->imports->rollbacks([$id]));
        $out['preview'] = $import['preview'] === null ? [] : json_decode((string) $import['preview'], true);
        $out['errors'] = array_map(static fn (array $e): array => [
            'line' => (int) $e['line_number'], 'type' => $e['data_type'] ?? null, 'field' => $e['field'], 'message' => $e['message'],
        ], $this->imports->errors($id));
        $out['audit'] = array_map(static fn (array $a): array => [
            'action' => $a['action'],
            'user' => $a['user_name'],
            'at' => self::iso($a['created_at']),
            'details' => $a['details'] === null ? null : json_decode((string) $a['details'], true),
        ], $this->imports->auditLog($id));

        return $out;
    }

    /** @return array{filename: string, content: string} */
    public function originalFile(int $id, array $user, string $ip): array
    {
        $import = $this->imports->findImport($id) ?? throw HttpException::notFound('Import not found.');
        if ($import['entity_type'] === ImportTypes::UNIFIED) {
            $content = $this->storedFile($import);
            $this->imports->audit($id, 'file_download', (int) $user['id'], $ip);

            return ['filename' => $this->downloadName($import, '', 'xlsx'), 'content' => $content, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }
        $parsed = ImportCsv::parse($this->storedFile($import));
        $rows = $parsed['rows'];
        $header = array_shift($rows) ?? [];
        $rows = PiiMask::maskTable($header, $rows);
        $this->imports->audit($id, 'file_download', (int) $user['id'], $ip);
        $filename = $this->downloadName($import, '');
        ExportAudit::record('import_file', $filename, count($rows), ['importId' => $id]);

        return ['filename' => $filename, 'content' => ImportCsv::document($header, $rows)];
    }

    /** @return array{filename: string, content: string} */
    public function errorsFile(int $id, array $user, string $ip): array
    {
        $import = $this->imports->findImport($id) ?? throw HttpException::notFound('Import not found.');
        $unified = $import['entity_type'] === ImportTypes::UNIFIED;
        $rows = [];
        foreach ($this->imports->errors($id) as $error) {
            $cells = $error['row_data'] === null ? [] : (json_decode((string) $error['row_data'], true) ?: []);
            $rows[] = $unified
                ? [(string) $error['line_number'], ImportTypes::DATA_TYPE_LABELS[(string) ($error['data_type'] ?? '')] ?? '', $error['field'], $error['message'], ...$cells]
                : [(string) $error['line_number'], $error['field'], $error['message'], ...$cells];
        }
        $definition = ImportTypes::find((string) $import['entity_type']);
        $header = $unified
            ? ['Row', 'Data Type', 'Column', 'Error', ...ImportTypes::UNIFIED_COLUMNS]
            : ['Row', 'Column', 'Error', ...array_column($definition['columns'] ?? [], 'header')];
        $rows = PiiMask::maskTable($header, $rows);
        $this->imports->audit($id, 'errors_download', (int) $user['id'], $ip, ['errors' => count($rows)]);
        $filename = $this->downloadName($import, '-errors');
        ExportAudit::record('import_errors', $filename, count($rows), ['importId' => $id]);

        return ['filename' => $filename, 'content' => ImportCsv::document($header, $rows)];
    }

    /**
     * The backup as a readable CSV that opens in Excel (see ImportBackupCsv). backup.json stays on disk
     * unchanged for rollback; its checksum is still verified before anything is downloaded.
     *
     * @return array{filename: string, content: string}
     */
    public function backupFile(int $id, array $user, string $ip): array
    {
        $import = $this->imports->findImport($id) ?? throw HttpException::notFound('Import not found.');
        $path = self::directory($id) . '/backup.json';
        if ($import['backup_sha256'] === null || !is_file($path)) {
            throw HttpException::notFound('This import has no backup. A backup is taken when an import is confirmed.');
        }
        $content = (string) file_get_contents($path);
        if (!hash_equals((string) $import['backup_sha256'], hash('sha256', $content))) {
            throw new HttpException(409, 'backup_integrity_failed', 'The backup file does not match its checksum and was not downloaded.');
        }
        $saved = json_decode($content, true);
        $csv = ImportBackupCsv::build($this->imports->changes($id), is_array($saved['data'] ?? null) ? $saved['data'] : [], $this->imports->recordCodes());
        $this->imports->audit($id, 'backup_download', (int) $user['id'], $ip);
        $filename = 'import-' . $id . '-backup.csv';
        ExportAudit::record('import_backup', $filename, max(0, substr_count($csv, "\n") - 1), ['importId' => $id]);

        return ['filename' => $filename, 'content' => $csv];
    }

    /**
     * Upload one .xlsx holding every Sales Office list on a single "Import" sheet.
     *
     * @param array<string, mixed>|null $upload
     * @param array<string, mixed> $user
     */
    private function uploadUnified(?array $upload, array $user, string $ip): array
    {
        $content = $this->readUpload($upload);
        $sheet = $this->parseXlsx($content);
        $name = $this->cleanFilename((string) $upload['name']);
        $sha = hash('sha256', $content);

        $id = $this->imports->createImport([
            'entity_type' => ImportTypes::UNIFIED,
            'original_filename' => $name,
            'stored_filename' => 'original.xlsx',
            'file_size' => strlen($content),
            'file_sha256' => $sha,
            'status' => 'previewed',
            'total_rows' => count($sheet['rows']),
            'uploaded_by' => (int) $user['id'],
        ]);
        $directory = self::directory($id);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            $this->imports->deleteImport($id);
            throw new \RuntimeException('Could not create the import folder.');
        }
        if (file_put_contents($directory . '/original.xlsx', $content, LOCK_EX) !== strlen($content)) {
            $this->imports->deleteImport($id);
            throw new \RuntimeException('Could not store the uploaded file.');
        }

        $result = $this->runUnified($sheet, false, null);
        $this->imports->replaceErrors($id, $result['errors']);
        $this->imports->updateImport($id, $this->countFields($result));
        $this->imports->audit($id, 'upload', (int) $user['id'], $ip, ['filename' => $name, 'rows' => count($sheet['rows']), 'sha256' => $sha]);

        return $this->detail($id);
    }

    /** @return array{filename: string, content: string, mime: string} */
    public function template(): array
    {
        $columns = array_slice(ImportTypes::UNIFIED_COLUMNS, 1, 8);
        $help = [
            ['Yorilife ERP - Sales Office import (one sheet)'],
            ['Fill in the sheet named "Import". Only that sheet is read; this sheet is for help only.'],
            ['Every cell is formatted as Text so codes, phone numbers and pincodes keep their leading zeros.'],
            ['Rows can be in any order. They are imported in this order: Location, Location HQ, 3rd Line Manager, 2nd Line Manager, 1st Line Manager, Headquarters, Stockist.'],
            ['An existing code is updated; a new code is created. A blank optional cell keeps the saved value.'],
            ['Do not use formulas. A cell with a formula makes its row fail.'],
            ['Up to ' . self::MAX_UNIFIED_ROWS . ' rows and 10 MB per file. State List: codes or names separated by |, e.g. GUJ|BIH.'],
            [''],
            ['Data Type', ...$columns],
        ];
        $labels = ['required' => 'Required', 'optional' => 'Optional', 'blank' => 'Leave blank'];
        foreach (ImportTypes::unified()['dataTypes'] as $entry) {
            $line = [$entry['label']];
            foreach ($columns as $column) {
                $line[] = $labels[$entry['columns'][$column]];
            }
            $help[] = $line;
        }
        $help[] = [''];
        $help[] = ['Parent Code: Location HQ = Location code; 2nd line = 3rd line code; 1st line = 2nd line code; Headquarters = 1st line code; Stockist = Headquarters code.'];
        $help[] = ['Headquarters rows use the Location HQ code (e.g. HQ-101) as Code; the name and address come from the Location HQ.'];

        return [
            'filename' => 'yorilife-import-template.xlsx',
            'content' => ImportXlsx::write(['Import' => [ImportTypes::UNIFIED_COLUMNS, ...ImportTypes::unifiedExample()], 'Instructions' => $help]),
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /** @return array{headers: list<string>, headerLine: int, rows: list<array<string, mixed>>} */
    private function parseXlsx(string $content): array
    {
        $sheet = ImportXlsx::read($content);
        $seen = [];
        foreach ($sheet['headers'] as $header) {
            if ($header === '') {
                throw HttpException::validation(['file' => 'The header row has an empty column name.']);
            }
            $key = mb_strtolower($header);
            if (isset($seen[$key])) {
                throw HttpException::validation(['file' => "Duplicate column name: {$header}."]);
            }
            $seen[$key] = true;
        }
        $missing = array_values(array_filter(ImportTypes::UNIFIED_COLUMNS, static fn (string $c): bool => $c !== 'Notes' && !isset($seen[mb_strtolower($c)])));
        if ($missing !== []) {
            throw HttpException::validation(['file' => 'Missing columns: ' . implode(', ', $missing) . '. Download the Excel template.']);
        }
        $known = array_map('mb_strtolower', ImportTypes::UNIFIED_COLUMNS);
        $unknown = array_values(array_filter($sheet['headers'], static fn (string $h): bool => !in_array(mb_strtolower($h), $known, true)));
        if ($unknown !== []) {
            throw HttpException::validation(['file' => 'Unknown columns: ' . implode(', ', $unknown) . '. Download the Excel template.']);
        }
        if ($sheet['rows'] === []) {
            throw HttpException::validation(['file' => 'The file has a header row but no data rows.']);
        }
        if (count($sheet['rows']) > self::MAX_UNIFIED_ROWS) {
            throw HttpException::validation(['file' => 'A file can contain up to ' . self::MAX_UNIFIED_ROWS . ' data rows. This file has ' . count($sheet['rows']) . '.']);
        }

        return $sheet;
    }

    /**
     * Validate (and when $apply is true, save) every row of a unified sheet.
     * Rows are processed by Data Type in dependency order, so a parent in the
     * same file is saved before its children, whatever the row order.
     */
    private function runUnified(array $sheet, bool $apply, ?int $importId): array
    {
        $work = function () use ($sheet, $importId): array {
            $index = [];
            foreach ($sheet['headers'] as $position => $header) {
                $index[mb_strtolower($header)] = $position;
            }
            $order = array_flip(ImportTypes::ORDER);
            $entries = [];
            foreach ($sheet['rows'] as $row) {
                $raw = static fn (string $column): string => trim((string) ($row['cells'][$index[mb_strtolower($column)] ?? -1] ?? ''));
                $entries[] = ['row' => $row, 'raw' => $raw, 'type' => ImportTypes::dataType($raw('Data Type'))];
            }
            usort($entries, static fn (array $a, array $b): int => [$a['type'] === null ? -1 : $order[$a['type']], $a['row']['line']]
                <=> [$b['type'] === null ? -1 : $order[$b['type']], $b['row']['line']]);

            $summary = ['rows' => [], 'errors' => [], 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'invalid' => 0];
            $seen = [];
            $failed = [];
            $groupOf = static fn (string $type): string => str_starts_with($type, 'manager') ? 'manager' : $type;
            $columnName = static fn (int $position): string => $sheet['headers'][$position] ?? ImportXlsx::letter($position);
            foreach ($entries as ['row' => $row, 'raw' => $raw, 'type' => $type]) {
                $line = (int) $row['line'];
                $code = $raw('Code');
                $errors = [];
                $warnings = [];
                $outcome = null;
                foreach ($row['formula'] as $position) {
                    $errors[] = [$columnName($position), 'Formulas are not allowed. Type the value as text.'];
                }
                foreach ($row['error'] as $position) {
                    $errors[] = [$columnName($position), 'This cell has an Excel error value.'];
                }
                if ($row['hidden']) {
                    $warnings[] = 'This row is hidden in Excel; it was still imported.';
                }
                if ($type === null) {
                    $value = $raw('Data Type');
                    $errors[] = ['Data Type', $value === '' ? 'This field is required.'
                        : 'Unknown data type "' . mb_substr($value, 0, 40) . '". Use one of: ' . implode(', ', ImportTypes::DATA_TYPE_LABELS) . '.'];
                } else {
                    $definition = ImportTypes::find($type);
                    $map = ImportTypes::UNIFIED_MAP[$type];
                    $label = ImportTypes::DATA_TYPE_LABELS[$type];
                    $values = [];
                    $numeric = array_flip($row['numeric']);
                    foreach ($map as $unified => $header) {
                        $value = $raw($unified);
                        if ($unified === 'Pincode' && isset($numeric[$index['pincode'] ?? -1]) && preg_match('/^[0-9]{5}$/', $value) === 1) {
                            $value = '0' . $value;
                        }
                        $values[mb_strtolower($header)] = $value;
                    }
                    foreach (ImportTypes::UNIFIED_COLUMNS as $unified) {
                        if ($unified !== 'Data Type' && $unified !== 'Notes' && !isset($map[$unified]) && $raw($unified) !== '') {
                            $warnings[] = "{$unified} is not used for {$label} and was ignored.";
                        }
                    }
                    $cell = static fn (string $header): string => $values[mb_strtolower($header)] ?? '';
                    $toUnified = array_flip($map);
                    foreach ($definition['columns'] as $column) {
                        if ($column['required'] && $cell($column['header']) === '') {
                            $errors[] = [$toUnified[$column['header']] ?? $column['header'], 'This field is required.'];
                        }
                    }
                    $group = $groupOf($type);
                    $key = mb_strtolower($code);
                    if ($errors === [] && isset($seen[$group][$key])) {
                        $errors[] = ['Code', 'This code is already used on row ' . $seen[$group][$key] . '.'];
                    }
                    $parentType = ImportTypes::UNIFIED_PARENT[$type] ?? null;
                    if ($errors === [] && $parentType !== null) {
                        $parentLine = $failed[$groupOf($parentType)][mb_strtolower($raw('Parent Code'))] ?? null;
                        if ($parentLine !== null) {
                            $errors[] = ['Parent Code', "Parent row {$parentLine} has errors."];
                        }
                    }
                    if ($errors === [] && $type === 'headquarters' && isset($failed['location-headquarters'][$key])) {
                        $errors[] = ['Code', 'Location HQ row ' . $failed['location-headquarters'][$key] . ' has errors.'];
                    }
                    if ($errors === []) {
                        $seen[$group][$key] = $line;
                        $this->imports->savepoint('import_row');
                        try {
                            $outcome = $this->applyRow($type, $definition, $cell, $line, $importId);
                            $this->imports->release('import_row');
                        } catch (HttpException $e) {
                            $this->imports->rollbackTo('import_row');
                            $fields = $e->details['fields'] ?? [];
                            if ($fields === []) {
                                $errors[] = ['Record', $e->getMessage()];
                            }
                            foreach ($fields as $field => $message) {
                                $header = $definition['fields'][(string) $field] ?? null;
                                $errors[] = [$header === null ? 'Record' : ($toUnified[$header] ?? $header), (string) $message];
                            }
                        } catch (\PDOException $e) {
                            $this->imports->rollbackTo('import_row');
                            error_log('[import] row ' . $line . ': ' . $e->getMessage());
                            $errors[] = ['Record', 'The database rejected this row. Check the codes it refers to.'];
                        }
                    }
                    if ($errors !== [] && $code !== '' && ($seen[$group][$key] ?? $line) === $line) {
                        $failed[$group][$key] ??= $line;
                    }
                }
                $typeLabel = $type === null ? null : ImportTypes::DATA_TYPE_LABELS[$type];
                if ($errors !== []) {
                    $summary['invalid']++;
                    foreach ($errors as [$field, $message]) {
                        $summary['errors'][] = ['line' => $line, 'type' => $type, 'field' => $field, 'message' => $message, 'row' => array_map('strval', $row['cells'])];
                    }
                    $summary['rows'][] = ['line' => $line, 'type' => $type, 'typeLabel' => $typeLabel, 'code' => $code, 'name' => $raw('Name'), 'action' => 'error',
                        'messages' => array_map(static fn (array $error): string => "{$error[0]}: {$error[1]}", $errors), 'warnings' => $warnings];
                    continue;
                }
                $summary[$outcome['action']]++;
                $summary['rows'][] = ['line' => $line, 'type' => $type, 'typeLabel' => $typeLabel, 'code' => $outcome['code'], 'action' => $outcome['action'],
                    'name' => $outcome['name'], 'messages' => [], 'warnings' => $warnings];
            }
            usort($summary['rows'], static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

            return $summary;
        };

        if ($apply) {
            return $work();
        }
        $pdo = \App\Database\Database::connection();
        $pdo->beginTransaction();
        try {
            return $work();
        } finally {
            $pdo->rollBack();
        }
    }
    /**
     * Create or update one record from a CSV row.
     *
     * @param callable(string): string $cell
     * @return array{action: string, code: string, name: string}
     */
    private function applyRow(string $type, array $definition, callable $cell, int $line, ?int $importId): array
    {
        $service = new SalesOfficeService($this->office);
        $table = $definition['table'];
        $code = $cell($definition['key']);
        $existingId = null;
        $payload = [];
        $sideEffects = [];

        switch ($type) {
            case 'location':
                $existing = $this->office->findLocationByCode(strtoupper($code));
                $existingId = $existing === null ? null : (int) $existing['id'];
                $payload = ['code' => $code, 'name' => $cell('State Name')];
                break;

            case 'location-headquarters':
                $existing = $this->office->findLocationHeadquartersByCode($this->hqCode($code));
                $existingId = $existing === null ? null : (int) $existing['id'];
                $location = $this->findLocation($cell('Location Code'));
                if ($location === null) {
                    throw HttpException::validation(['locationId' => 'No location has this code.']);
                }
                $payload = [
                    'locationId' => (int) $location['id'],
                    'name' => $cell('Headquarters Name'),
                    'code' => $code,
                    'address' => $cell('Address'),
                    'pincode' => $cell('Pincode'),
                    'phone' => $cell('Phone Number'),
                    'email' => $cell('Email'),
                ];
                if ($existingId !== null) {
                    $linked = $this->office->findHeadquartersByLocationOffice($existingId);
                    if ($linked !== null) {
                        $sideEffects[] = ['headquarters', (int) $linked['id'], $this->imports->snapshot('headquarters', (int) $linked['id'])];
                    }
                }
                break;

            case 'manager-3':
            case 'manager-2':
            case 'manager-1':
                $level = (int) substr($type, -1);
                $existing = $this->office->findManagerByCode($code);
                if ($existing !== null && (int) $existing['level'] !== $level) {
                    throw HttpException::validation(['code' => 'This code belongs to a ' . self::ordinal((int) $existing['level']) . ' line manager.']);
                }
                $existingId = $existing === null ? null : (int) $existing['id'];
                $payload = [
                    'name' => $cell('Name'),
                    'code' => $code,
                    'level' => $level,
                    'email' => $this->keep($cell('Email'), $existing['email'] ?? null),
                    'phone' => $this->keep($cell('Phone Number'), $existing['phone'] ?? null),
                ];
                $states = $this->stateIds($cell('State List'));
                if ($states === null) {
                    throw HttpException::validation(['stateIds' => 'Unknown state in the list. Use location codes or names separated by |.']);
                }
                if ($states !== []) {
                    $payload['stateIds'] = $states;
                } elseif ($level !== 1) {
                    throw HttpException::validation(['stateIds' => 'Enter at least one state.']);
                }
                if ($level !== 3) {
                    $parentLevel = $level + 1;
                    $header = $parentLevel === 3 ? 'Reports To (3rd Line Code)' : 'Reports To (2nd Line Code)';
                    $parent = $this->office->findManagerByCode($cell($header));
                    if ($parent === null || (int) $parent['level'] !== $parentLevel) {
                        throw HttpException::validation(['parentId' => 'No ' . self::ordinal($parentLevel) . ' line manager has this code.']);
                    }
                    $payload['parentType'] = 'manager' . $parentLevel;
                    $payload['parentId'] = (int) $parent['id'];
                }
                break;

            case 'headquarters':
                $office = $this->office->findLocationHeadquartersByCode($this->hqCode($code));
                if ($office === null) {
                    throw HttpException::validation(['locationHeadquartersId' => 'No location headquarters has this code. Import it on Location headquarters first.']);
                }
                $existing = $this->office->findHeadquartersByCode((string) $office['code']);
                $existingId = $existing === null ? null : (int) $existing['id'];
                $manager = $this->office->findManagerByCode($cell('1st Line Manager Code'));
                if ($manager === null || (int) $manager['level'] !== 1) {
                    throw HttpException::validation(['parentManagerId' => 'No 1st line manager has this code.']);
                }
                $payload = ['locationHeadquartersId' => (int) $office['id'], 'parentManagerId' => (int) $manager['id']];
                break;

            case 'stockist':
                if (preg_match('/^ST-[0-9]+$/i', $code) !== 1) {
                    throw HttpException::validation(['code' => 'Enter numbers only after ST-.']);
                }
                $existing = $this->office->findStockistByCode($code);
                $existingId = $existing === null ? null : (int) $existing['id'];
                $hq = $this->office->findHeadquartersByCode($this->hqCode($cell('Headquarters Code')));
                if ($hq === null) {
                    throw HttpException::validation(['headquartersId' => 'No headquarters has this code.']);
                }
                $payload = [
                    'name' => $cell('Stockist Name'),
                    'code' => $code,
                    'headquartersId' => (int) $hq['id'],
                    'address' => $this->keep($cell('Address'), $existing['address'] ?? null),
                    'pincode' => $this->keep($cell('Pincode'), $existing['pincode'] ?? null),
                    'phone' => $this->keep($cell('Phone Number'), $existing['phone'] ?? null),
                    'email' => $this->keep($cell('Email'), $existing['email'] ?? null),
                ];
                break;

            default:
                throw HttpException::validation(['type' => 'Choose what to import.']);
        }

        $before = $existingId === null ? null : $this->imports->snapshot($table, $existingId);
        $id = $existingId ?? 0;
        switch ($type) {
            case 'location':
                $id = $existingId === null ? (int) $service->createLocation($payload)['id'] : $this->touch($service->updateLocation($existingId, $payload), $existingId);
                break;
            case 'location-headquarters':
                if ($existingId === null) {
                    $service->createLocationHeadquarters($payload);
                    $id = (int) ($this->office->findLocationHeadquartersByCode($this->hqCode($code))['id'] ?? 0);
                } else {
                    $service->updateLocationHeadquarters($existingId, $payload);
                }
                break;
            case 'headquarters':
                $id = $existingId === null ? (int) $service->createHeadquarters($payload)['id'] : $this->touch($service->updateHeadquarters($existingId, $payload), $existingId);
                break;
            case 'stockist':
                $id = $existingId === null ? (int) $service->createStockist($payload)['id'] : $this->touch($service->updateStockist($existingId, $payload), $existingId);
                break;
            default:
                $id = $existingId === null ? (int) $service->createManager($payload)['id'] : $this->touch($service->updateManager($existingId, $payload), $existingId);
        }
        if ($id <= 0) {
            throw HttpException::validation(['code' => 'The record could not be saved.']);
        }
        $after = $this->imports->snapshot($table, $id);
        $action = $before === null ? 'created' : ($before === $after ? 'unchanged' : 'updated');
        $savedCode = (string) ($after['code'] ?? $code);

        if ($importId !== null && $action !== 'unchanged') {
            $this->imports->addChange([
                'import_id' => $importId,
                'line_number' => $line,
                'data_type' => $type,
                'record_table' => $table,
                'record_id' => $id,
                'record_code' => $savedCode,
                'action' => $action,
                'before_data' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'after_data' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }
        foreach ($sideEffects as [$sideTable, $sideId, $sideBefore]) {
            $sideAfter = $this->imports->snapshot($sideTable, $sideId);
            if ($importId !== null && $sideBefore !== null && $sideAfter !== null && $sideBefore !== $sideAfter) {
                $this->imports->addChange([
                    'import_id' => $importId,
                    'line_number' => $line,
                    'data_type' => $sideTable === 'headquarters' ? 'headquarters' : $type,
                    'record_table' => $sideTable,
                    'record_id' => $sideId,
                    'record_code' => $sideAfter['code'] ?? null,
                    'action' => 'updated',
                    'before_data' => json_encode($sideBefore, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'after_data' => json_encode($sideAfter, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        }

        return ['action' => $action, 'code' => $savedCode, 'name' => (string) ($after['name'] ?? '')];
    }

    private function touch(mixed $result, int $id): int
    {
        return $id;
    }

    /** An empty optional cell keeps the value already stored on an existing record. */
    private function keep(string $value, mixed $current): ?string
    {
        if ($value !== '') {
            return $value;
        }

        return $current === null || $current === '' ? null : (string) $current;
    }

    private function hqCode(string $code): string
    {
        return preg_match('/^HQ-([0-9]+)$/i', $code, $m) === 1 ? 'HQ-' . $m[1] : $code;
    }

    /** @return array<string, mixed>|null */
    private function findLocation(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        return $this->office->findLocationByCode(strtoupper($value)) ?? $this->office->findLocationByName($value);
    }

    /** @return list<int>|null null when a state is unknown */
    private function stateIds(string $value): ?array
    {
        $ids = [];
        foreach (preg_split('/\s*\|\s*/', $value) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $location = $this->findLocation($part);
            if ($location === null) {
                return null;
            }
            $ids[(int) $location['id']] = (int) $location['id'];
        }

        return array_values($ids);
    }

    /** @param array<string, mixed>|null $upload */
    private function readUpload(?array $upload): string
    {
        if ($upload === null || !isset($upload['error']) || is_array($upload['error'])) {
            throw HttpException::validation(['file' => 'Choose an Excel (.xlsx) file.']);
        }
        $error = (int) $upload['error'];
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw HttpException::validation(['file' => 'The file is larger than the server allows (limit ' . ini_get('upload_max_filesize') . ').']);
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw HttpException::validation(['file' => 'Choose an Excel (.xlsx) file.']);
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
            throw HttpException::validation(['file' => 'The file could not be uploaded. Try again.']);
        }
        if (strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            throw HttpException::validation(['file' => 'Only .xlsx files can be imported (not .xls, .xlsm or .csv).']);
        }
        $size = (int) $upload['size'];
        if ($size <= 0) {
            throw HttpException::validation(['file' => 'The file is empty.']);
        }
        if ($size > self::MAX_BYTES) {
            throw HttpException::validation(['file' => 'The file is larger than 10 MB.']);
        }
        $content = file_get_contents((string) $upload['tmp_name']);
        if ($content === false) {
            throw HttpException::validation(['file' => 'The file could not be read.']);
        }

        return $content;
    }

    private function storedFile(array $import): string
    {
        $stored = in_array($import['stored_filename'] ?? '', ['original.csv', 'original.xlsx'], true) ? (string) $import['stored_filename'] : 'original.csv';
        $path = self::directory((int) $import['id']) . '/' . $stored;
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw HttpException::notFound('The uploaded file is no longer stored.');
        }
        if (!hash_equals((string) $import['file_sha256'], hash('sha256', $content))) {
            throw new HttpException(409, 'file_integrity_failed', 'The stored file does not match its checksum.');
        }

        return $content;
    }

    private function cleanFilename(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^\pL\pN ._()-]+/u', '_', $base) ?? 'import.csv';

        return mb_substr($base === '' ? 'import.csv' : $base, 0, 200);
    }

    private function downloadName(array $import, string $suffix, string $extension = 'csv'): string
    {
        $base = preg_replace('/\.(csv|xlsx)$/i', '', (string) $import['original_filename']);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $base) ?: 'import';

        return 'import-' . (int) $import['id'] . '-' . $base . $suffix . '.' . $extension;
    }

    /** @return array<string, mixed> */
    private function countFields(array $result): array
    {
        return [
            'created_count' => $result['created'],
            'updated_count' => $result['updated'],
            'unchanged_count' => $result['unchanged'],
            'error_count' => $result['invalid'],
            'preview' => json_encode($result['rows'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array<string, mixed> */
    /**
     * @param array<int, int> $pending import id => records still pending rollback
     * @param array<int, list<array<string, mixed>>> $runs import id => rollback runs
     */
    private function present(array $row, array $pending = [], array $runs = []): array
    {
        $id = (int) $row['id'];
        $left = $pending[$id] ?? 0;
        $definition = ImportTypes::find((string) $row['entity_type']);

        return [
            'id' => (string) $row['id'],
            'type' => $row['entity_type'],
            'typeLabel' => $row['entity_type'] === ImportTypes::UNIFIED ? ImportTypes::UNIFIED_LABEL : ($definition['label'] ?? $row['entity_type']),
            'filename' => $row['original_filename'],
            'fileSize' => (int) $row['file_size'],
            'sha256' => $row['file_sha256'],
            'status' => $row['status'],
            'totalRows' => (int) $row['total_rows'],
            'created' => (int) $row['created_count'],
            'updated' => (int) $row['updated_count'],
            'unchanged' => (int) $row['unchanged_count'],
            'errorRows' => (int) $row['error_count'],
            'hasBackup' => $row['backup_sha256'] !== null,
            'uploadedBy' => $row['uploaded_by_name'],
            'uploadedAt' => self::iso($row['uploaded_at']),
            'importedBy' => $row['imported_by_name'],
            'importedAt' => self::iso($row['imported_at']),
            'rolledBackBy' => $row['rolled_back_by_name'],
            'rolledBackAt' => self::iso($row['rolled_back_at']),
            'rollback' => $row['rollback_summary'] === null ? null : json_decode((string) $row['rollback_summary'], true),
            'pendingRollback' => $left,
            'canRollback' => $left > 0 && in_array($row['status'], ['completed', 'partially_rolled_back'], true),
            'rollbacks' => array_map(static fn (array $r): array => [
                'id' => (string) $r['id'],
                'by' => $r['user_name'],
                'at' => self::iso($r['created_at']),
                'restored' => (int) $r['restored'],
                'deleted' => (int) $r['deleted'],
                'skipped' => (int) $r['skipped'],
                'notSelected' => (int) $r['not_selected'],
                'items' => array_map(static fn (array $i): array => [
                    'code' => $i['code'] ?? null, 'type' => $i['type'] ?? null, 'table' => $i['table'] ?? null,
                    'action' => ($i['action'] ?? '') === 'created' ? 'Delete' : 'Restore',
                    'result' => $i['result'] ?? null, 'note' => $i['note'] ?? null,
                ], array_values(array_filter(json_decode((string) $r['items'], true) ?: [], static fn ($i): bool => is_array($i) && ($i['result'] ?? '') !== 'not_selected'))),
            ], $runs[$id] ?? []),
        ];
    }

    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_replace(' ', 'T', (string) $value) . 'Z';
    }

    private static function ordinal(int $level): string
    {
        return [1 => '1st', 2 => '2nd', 3 => '3rd'][$level] ?? (string) $level;
    }
}
