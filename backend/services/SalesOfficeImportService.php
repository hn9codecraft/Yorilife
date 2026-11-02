<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SalesOfficeRepository;
use App\Utils\HttpException;

/**
 * Adds new Sales Office rows from a CSV already parsed into headers and cells.
 * A preview validates every row and saves nothing. Confirming writes only the
 * valid rows, in one transaction, and leaves invalid rows untouched.
 */
final class SalesOfficeImportService
{
    private const MAX_ROWS = 500;

    /** @var array<string, array{required: list<string>, optional: list<string>, labels: array<string, string>}> */
    private const MODULES = [
        'manager-3' => [
            'required' => ['Name', 'Employee Code', 'State List'],
            'optional' => ['Email', 'Phone Number'],
            'labels' => [
                'name' => 'Name', 'code' => 'Employee Code', 'email' => 'Email',
                'phone' => 'Phone Number', 'stateIds' => 'State List',
            ],
        ],
        'manager-2' => [
            'required' => ['Name', 'Employee Code', 'Reports To', 'State List'],
            'optional' => ['Email', 'Phone Number'],
            'labels' => [
                'name' => 'Name', 'code' => 'Employee Code', 'email' => 'Email',
                'phone' => 'Phone Number', 'stateIds' => 'State List', 'parentId' => 'Reports To',
                'parentType' => 'Reports To',
            ],
        ],
        'manager-1' => [
            'required' => ['Name', 'Employee Code', 'Reports To'],
            'optional' => [],
            'labels' => [
                'name' => 'Name', 'code' => 'Employee Code', 'parentId' => 'Reports To', 'parentType' => 'Reports To',
            ],
        ],
        'headquarters' => [
            'required' => ['Headquarters name', '1st line manager', 'State', 'Headquarters address', 'Pincode', 'Phone number'],
            'optional' => ['Code', 'Email'],
            'labels' => [
                'name' => 'Headquarters name', 'parentManagerId' => '1st line manager', 'state' => 'State',
                'code' => 'Code', 'address' => 'Headquarters address', 'pincode' => 'Pincode', 'phone' => 'Phone number',
            ],
        ],
        'stockist' => [
            'required' => ['Stockist name', 'Headquarters'],
            'optional' => ['Stockist code', 'Address', 'Pincode', 'Phone number', 'Email'],
            'labels' => [
                'name' => 'Stockist name', 'code' => 'Stockist code', 'headquartersId' => 'Headquarters',
            ],
        ],
    ];

    public function __construct(private SalesOfficeRepository $records = new SalesOfficeRepository())
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{total: int, valid: int, invalid: int, imported: int, errors: list<array{row: int, field: string, message: string}>}
     */
    public function import(array $input): array
    {
        $fileErrors = [];
        Input::onlyFields($input, ['module', 'headers', 'rows', 'confirm'], $fileErrors);
        if ($fileErrors !== []) {
            throw HttpException::validation($fileErrors);
        }
        $module = $input['module'] ?? null;
        if (!is_string($module) || !isset(self::MODULES[$module])) {
            throw HttpException::validation(['module' => 'Choose a sales office list to import.']);
        }
        $confirm = $input['confirm'] ?? false;
        if (!is_bool($confirm)) {
            throw HttpException::validation(['confirm' => 'Confirm must be true or false.']);
        }
        $headers = $this->headerList($input['headers'] ?? null);
        $rows = $this->rowList($input['rows'] ?? null);
        $missing = $this->missingHeaders($headers, self::MODULES[$module]['required']);
        if ($missing !== []) {
            throw HttpException::validation(['headers' => 'Missing required columns: ' . implode(', ', $missing) . '.']);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw HttpException::validation(['rows' => 'A file can contain up to ' . self::MAX_ROWS . ' data rows.']);
        }

        $summary = ['total' => count($rows), 'valid' => 0, 'invalid' => 0, 'imported' => 0, 'errors' => []];
        try {
            $this->records->transaction(function () use (&$summary, $module, $headers, $rows, $confirm): void {
                $summary = $this->apply($module, $headers, $rows, $confirm);
                if (!$confirm) {
                    throw new SalesOfficeImportPreviewStop();
                }
            });
        } catch (SalesOfficeImportPreviewStop) {
            $summary['imported'] = 0;
        }

        return $summary;
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @return array{total: int, valid: int, invalid: int, imported: int, errors: list<array{row: int, field: string, message: string}>}
     */
    private function apply(string $module, array $headers, array $rows, bool $confirm): array
    {
        $columns = [];
        foreach ($headers as $index => $header) {
            $columns[mb_strtolower($header)] = $index;
        }
        $office = new SalesOfficeService();
        $managers = $this->records->managers();
        $locations = $this->records->locations();
        $headquarters = $this->records->headquarters();
        $seenCodes = [];
        $seenEmails = [];
        $errors = [];
        $valid = 0;

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            if (count($row) !== count($headers)) {
                $errors[] = ['row' => $rowNumber, 'field' => 'Row', 'message' => 'This row has a different number of columns than the header.'];
                continue;
            }
            $cell = function (string $header) use ($columns, $row): string {
                $key = mb_strtolower($header);
                if (!isset($columns[$key])) {
                    return '';
                }
                return trim($row[$columns[$key]]);
            };
            $rowErrors = $this->checkRow($module, $cell, $managers, $locations, $headquarters, $seenCodes, $seenEmails);
            if ($rowErrors !== []) {
                foreach ($rowErrors as [$field, $message]) {
                    $errors[] = ['row' => $rowNumber, 'field' => $field, 'message' => $message];
                }
                continue;
            }
            try {
                $this->saveRow($office, $module, $cell, $managers, $locations, $headquarters);
                $valid++;
                $code = $this->trackedCode($module, $cell);
                if ($code !== '') {
                    $seenCodes[mb_strtolower($code)] = true;
                }
                $email = in_array($module, ['manager-3', 'manager-2'], true) ? mb_strtolower($cell('Email')) : '';
                if ($email !== '') {
                    $seenEmails[$email] = true;
                }
                if ($module === 'manager-3' || $module === 'manager-2' || $module === 'manager-1') {
                    $managers = $this->records->managers();
                }
            } catch (HttpException $exception) {
                if (!in_array($exception->status, [422, 404], true)) {
                    throw $exception;
                }
                $fields = $exception->details['fields'] ?? [];
                if ($fields === []) {
                    $errors[] = ['row' => $rowNumber, 'field' => 'Record', 'message' => $exception->getMessage()];
                    continue;
                }
                foreach ($fields as $field => $message) {
                    $label = self::MODULES[$module]['labels'][(string) $field] ?? (string) $field;
                    $errors[] = ['row' => $rowNumber, 'field' => $label, 'message' => (string) $message];
                }
            }
        }

        $invalidRows = count(array_unique(array_column($errors, 'row')));

        return [
            'total' => count($rows),
            'valid' => $valid,
            'invalid' => $invalidRows,
            'imported' => $confirm ? $valid : 0,
            'errors' => $errors,
        ];
    }

    /**
     * @param callable(string): string $cell
     * @param list<array<string, mixed>> $managers
     * @param list<array<string, mixed>> $locations
     * @param list<array<string, mixed>> $headquarters
     * @param array<string, true> $seenCodes
     * @param array<string, true> $seenEmails
     * @return list<array{0: string, 1: string}>
     */
    private function checkRow(string $module, callable $cell, array $managers, array $locations, array $headquarters, array $seenCodes, array $seenEmails): array
    {
        $errors = [];
        foreach (self::MODULES[$module]['required'] as $header) {
            if ($cell($header) === '') {
                $errors[] = [$header, 'This field is required.'];
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        if (str_starts_with($module, 'manager-')) {
            $level = (int) substr($module, -1);
            $code = $cell('Employee Code');
            if (preg_match('/^ER-M' . $level . '-[0-9]+$/i', $code) !== 1) {
                $errors[] = ['Employee Code', "Enter numbers only after ER-M{$level}-."];
            } elseif (isset($seenCodes[mb_strtolower($code)])) {
                $errors[] = ['Employee Code', 'This code is already used by an earlier row.'];
            }
            if ($level !== 1) {
                $email = $cell('Email');
                if ($email !== '' && filter_var(mb_strtolower($email), FILTER_VALIDATE_EMAIL) === false) {
                    $errors[] = ['Email', 'Enter a valid email address.'];
                } elseif ($email !== '' && isset($seenEmails[mb_strtolower($email)])) {
                    $errors[] = ['Email', 'This email address is already used by an earlier row.'];
                }
                $phone = $cell('Phone Number');
                if ($phone !== '' && (strlen($phone) > 20 || preg_match('/^\+?[0-9][0-9\s().-]{5,19}$/', $phone) !== 1)) {
                    $errors[] = ['Phone Number', 'Enter a valid phone number.'];
                }
                foreach ($this->stateNames($cell('State List')) as $name) {
                    if ($this->findLocation($locations, $name) === null) {
                        $errors[] = ['State List', $name . ' is not in the Location list.'];
                    }
                }
            }
            if ($level !== 3) {
                $parentLevel = $level === 2 ? 3 : 2;
                $parentLabel = $level === 2 ? '3rd line manager' : '2nd line manager';
                $matches = $this->matchManagers($managers, $cell('Reports To'), $parentLevel);
                if (count($matches) === 0) {
                    $errors[] = ['Reports To', "No {$parentLabel} matches this value."];
                } elseif (count($matches) > 1) {
                    $errors[] = ['Reports To', 'More than one manager has this name. Use the employee code.'];
                }
            }
        } elseif ($module === 'headquarters') {
            $state = $this->canonicalState($cell('State'));
            if ($state === null) {
                $errors[] = ['State', 'Choose a state or union territory from the list.'];
            }
            if (preg_match('/^[1-9][0-9]{5}$/', $cell('Pincode')) !== 1) {
                $errors[] = ['Pincode', 'Enter a 6-digit pincode.'];
            }
            $phone = $cell('Phone number');
            if (strlen($phone) > 20 || preg_match('/^\+?[0-9][0-9\s().-]{5,19}$/', $phone) !== 1) {
                $errors[] = ['Phone number', 'Enter a valid phone number.'];
            }
            $code = $cell('Code');
            if ($code !== '' && isset($seenCodes[mb_strtolower($code)])) {
                $errors[] = ['Code', 'This code is already used by an earlier row.'];
            }
            $matches = $this->matchManagers($managers, $cell('1st line manager'), 1);
            if (count($matches) === 0) {
                $errors[] = ['1st line manager', 'No 1st line manager matches this value.'];
            } elseif (count($matches) > 1) {
                $errors[] = ['1st line manager', 'More than one manager has this name. Use the employee code.'];
            }
        } else {
            $code = $cell('Stockist code');
            if ($code !== '' && isset($seenCodes[mb_strtolower($code)])) {
                $errors[] = ['Stockist code', 'This code is already used by an earlier row.'];
            }
            $phone = $cell('Phone number');
            if ($phone !== '' && (strlen($phone) > 20 || preg_match('/^\+?[0-9][0-9\s().-]{5,19}$/', $phone) !== 1)) {
                $errors[] = ['Phone number', 'Enter a valid phone number.'];
            }
            $email = $cell('Email');
            if ($email !== '' && filter_var(mb_strtolower($email), FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = ['Email', 'Enter a valid email address.'];
            }
            if ($cell('Pincode') !== '' && preg_match('/^[1-9][0-9]{5}$/', $cell('Pincode')) !== 1) {
                $errors[] = ['Pincode', 'Enter a 6-digit pincode.'];
            }
            $matches = $this->matchHeadquarters($headquarters, $cell('Headquarters'));
            if (count($matches) === 0) {
                $errors[] = ['Headquarters', 'No headquarters matches this value.'];
            } elseif (count($matches) > 1) {
                $errors[] = ['Headquarters', 'More than one headquarters has this name. Use the headquarters code.'];
            }
        }

        return $errors;
    }

    /**
     * @param callable(string): string $cell
     * @param list<array<string, mixed>> $managers
     * @param list<array<string, mixed>> $locations
     * @param list<array<string, mixed>> $headquarters
     */
    private function saveRow(SalesOfficeService $office, string $module, callable $cell, array $managers, array $locations, array $headquarters): void
    {
        if ($module === 'manager-3' || $module === 'manager-2' || $module === 'manager-1') {
            $level = (int) substr($module, -1);
            $payload = [
                'name' => $cell('Name'),
                'code' => $cell('Employee Code'),
                'level' => $level,
            ];
            if ($level !== 1) {
                $payload['email'] = $cell('Email');
                $payload['phone'] = $cell('Phone Number');
                $payload['stateIds'] = array_map(
                    fn (string $name): int => (int) $this->findLocation($locations, $name)['id'],
                    $this->stateNames($cell('State List')),
                );
            }
            if ($level !== 3) {
                $parentLevel = $level === 2 ? 3 : 2;
                $parent = $this->matchManagers($managers, $cell('Reports To'), $parentLevel)[0];
                $payload['parentType'] = 'manager' . $parentLevel;
                $payload['parentId'] = (int) $parent['id'];
            }
            $office->createManager($payload);
            return;
        }
        if ($module === 'headquarters') {
            $parent = $this->matchManagers($managers, $cell('1st line manager'), 1)[0];
            $office->createHeadquarters([
                'name' => $cell('Headquarters name'),
                'state' => $this->canonicalState($cell('State')),
                'address' => $cell('Headquarters address'),
                'pincode' => $cell('Pincode'),
                'phone' => $cell('Phone number'),
                'code' => $cell('Code'),
                'parentManagerId' => (int) $parent['id'],
            ] + ($cell('Email') !== '' ? ['email' => $cell('Email')] : []));
            return;
        }
        $hq = $this->matchHeadquarters($headquarters, $cell('Headquarters'))[0];
        $office->createStockist([
            'name' => $cell('Stockist name'),
            'code' => $cell('Stockist code'),
            'headquartersId' => (int) $hq['id'],
            'address' => $cell('Address'),
            'pincode' => $cell('Pincode'),
            'phone' => $cell('Phone number'),
            'email' => $cell('Email'),
        ]);
    }

    /** @param callable(string): string $cell */
    private function trackedCode(string $module, callable $cell): string
    {
        return match ($module) {
            'headquarters' => $cell('Code'),
            'stockist' => $cell('Stockist code'),
            default => $cell('Employee Code'),
        };
    }

    /** @return list<string> */
    private function stateNames(string $value): array
    {
        $parts = preg_split('/\s*[;|]\s*/', $value) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
    }

    /** @param list<array<string, mixed>> $locations @return array<string, mixed>|null */
    private function findLocation(array $locations, string $name): ?array
    {
        foreach ($locations as $location) {
            if (mb_strtolower((string) $location['name']) === mb_strtolower($name)) {
                return $location;
            }
        }

        return null;
    }

    /**
     * Prefer an employee-code match. Otherwise an exact name match.
     *
     * @param list<array<string, mixed>> $managers
     * @return list<array<string, mixed>>
     */
    private function matchManagers(array $managers, string $value, int $level): array
    {
        $needle = mb_strtolower($value);
        $byCode = [];
        $byName = [];
        foreach ($managers as $manager) {
            if ((int) $manager['level'] !== $level) {
                continue;
            }
            if (mb_strtolower((string) ($manager['code'] ?? '')) === $needle) {
                $byCode[] = $manager;
            }
            if (mb_strtolower((string) $manager['name']) === $needle) {
                $byName[] = $manager;
            }
        }

        return $byCode !== [] ? $byCode : $byName;
    }

    /** @param list<array<string, mixed>> $headquarters @return list<array<string, mixed>> */
    private function matchHeadquarters(array $headquarters, string $value): array
    {
        $needle = mb_strtolower($value);
        $byCode = [];
        $byName = [];
        foreach ($headquarters as $hq) {
            if ($needle !== '' && mb_strtolower((string) ($hq['code'] ?? '')) === $needle) {
                $byCode[] = $hq;
            }
            if (mb_strtolower((string) $hq['name']) === $needle) {
                $byName[] = $hq;
            }
        }

        return $byCode !== [] ? $byCode : $byName;
    }

    private function canonicalState(string $value): ?string
    {
        foreach (require dirname(__DIR__) . '/config/states.php' as $state) {
            if (mb_strtolower($state) === mb_strtolower($value)) {
                return $state;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function headerList(mixed $headers): array
    {
        if (!is_array($headers) || $headers === [] || array_is_list($headers) === false) {
            throw HttpException::validation(['headers' => 'The first row must contain column names.']);
        }
        $names = [];
        $seen = [];
        foreach ($headers as $header) {
            if (!is_string($header) || trim($header) === '') {
                throw HttpException::validation(['headers' => 'The header row has an empty column name.']);
            }
            $name = trim($header);
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                throw HttpException::validation(['headers' => 'Duplicate column names: ' . $name . '.']);
            }
            $seen[$key] = true;
            $names[] = $name;
        }

        return $names;
    }

    /** @return list<list<string>> */
    private function rowList(mixed $rows): array
    {
        if (!is_array($rows) || array_is_list($rows) === false) {
            throw HttpException::validation(['rows' => 'The file has no data rows.']);
        }
        if ($rows === []) {
            throw HttpException::validation(['rows' => 'The file has a header row but no data rows.']);
        }
        $parsed = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row) === false) {
                throw HttpException::validation(['rows' => 'Each data row must be a list of text values.']);
            }
            $cells = [];
            foreach ($row as $cell) {
                if (is_int($cell) || is_float($cell)) {
                    throw HttpException::validation(['rows' => 'Send every cell as text so leading zeros are kept.']);
                }
                if ($cell === null) {
                    $cells[] = '';
                    continue;
                }
                if (!is_string($cell)) {
                    throw HttpException::validation(['rows' => 'Each data row must be a list of text values.']);
                }
                $cells[] = $cell;
            }
            $parsed[] = $cells;
        }

        return $parsed;
    }

    /** @param list<string> $headers @param list<string> $required @return list<string> */
    private function missingHeaders(array $headers, array $required): array
    {
        $present = array_map(mb_strtolower(...), $headers);

        return array_values(array_filter($required, static fn (string $header): bool => !in_array(mb_strtolower($header), $present, true)));
    }
}

/** Thrown only to roll a preview back. It is caught by the import service. */
final class SalesOfficeImportPreviewStop extends \RuntimeException
{
}
