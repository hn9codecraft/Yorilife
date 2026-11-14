<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SalesEmployeeRepository;
use App\Utils\HttpException;

/**
 * Sales Office employee list. Every field is optional. Designations are listed
 * from the top of the reporting line to the bottom; the same order lives in
 * frontend/src/data/designations.js.
 */
final class SalesEmployeeService
{
    /** @var array<string, string> */
    public const DESIGNATIONS = [
        'GM' => 'GM - General Manager',
        'ZSM' => 'ZSM - Zonal Sales Manager',
        'DSM' => 'DSM - Direct Sales Manager',
        'RBM' => 'RBM - Region Business Manager',
        'DBM' => 'DBM - Deputy Business Manager',
        'ABM' => 'ABM - Area Business Manager',
        'BO' => 'BO - Business Officer',
    ];

    public function __construct(private readonly SalesEmployeeRepository $repository = new SalesEmployeeRepository())
    {
    }

    /** @return list<string> designation codes strictly above $code, top first */
    public static function above(?string $code): array
    {
        if ($code === null || !isset(self::DESIGNATIONS[$code])) {
            return [];
        }
        $codes = array_keys(self::DESIGNATIONS);
        $index = array_search($code, $codes, true);

        return array_slice($codes, 0, $index === false ? 0 : $index);
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map($this->present(...), $this->repository->all());
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        $values = $this->validate($input, null);
        $id = $this->repository->insert($values['fields'], $values['managers']);
        $created = $this->repository->find($id);
        AuditLogger::record([
            'module' => 'sales_office',
            'action' => 'create',
            'targetType' => 'sales_employee',
            'targetId' => $id,
            'targetLabel' => self::label($created),
            'details' => ['designation' => $created['designation']],
        ]);

        return $this->present($created);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): array
    {
        $current = $this->repository->find($id) ?? throw HttpException::notFound('Employee not found.');
        $values = $this->validate($input, $current);
        $this->repository->update($id, $values['fields'], $values['managers'], $values['fields']['designation']);
        $updated = $this->repository->find($id);
        AuditLogger::record([
            'module' => 'sales_office',
            'action' => 'update',
            'targetType' => 'sales_employee',
            'targetId' => $id,
            'targetLabel' => self::label($updated),
            'details' => ['designation' => $updated['designation']],
        ]);

        return $this->present($updated);
    }

    public function delete(int $id): void
    {
        $current = $this->repository->find($id) ?? throw HttpException::notFound('Employee not found.');
        $this->repository->softDelete($id, UtcTime::now());
        AuditLogger::record([
            'module' => 'sales_office',
            'action' => 'delete',
            'targetType' => 'sales_employee',
            'targetId' => $id,
            'targetLabel' => self::label($current),
        ]);
    }

    /**
     * A missing key on update keeps the stored value. An empty string clears it.
     * Manager slots that are no longer above the designation are dropped.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @return array{fields: array<string, mixed>, managers: array<string, int>}
     */
    private function validate(array $input, ?array $current): array
    {
        $errors = [];
        Input::onlyFields($input, [
            'name', 'employeeCode', 'phone', 'email', 'designation', 'locationId', 'locationHeadquartersId', 'managers',
        ], $errors);
        $name = $this->textValue($input, 'name', 'name', 150, 'name', $current, $errors);
        $code = $this->textValue($input, 'employeeCode', 'employee code', 100, 'employee_code', $current, $errors);
        $phone = $this->textValue($input, 'phone', 'phone number', 30, 'phone', $current, $errors);
        $email = $this->textValue($input, 'email', 'email', 254, 'email', $current, $errors);
        if ($email !== null && array_key_exists('email', $input) && !isset($errors['email'])) {
            $email = mb_strtolower($email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            }
        }
        $designation = $this->designation($input, $current, $errors);
        $locationId = $this->foreignId($input, 'locationId', 'location_id', $current, $errors, 'Choose a state from the list.', $this->repository->locationExists(...));
        $headquartersId = $this->foreignId(
            $input,
            'locationHeadquartersId',
            'location_headquarters_id',
            $current,
            $errors,
            'Choose a headquarters from the list.',
            fn (int $id): bool => $this->repository->locationHeadquarters($id) !== null,
        );
        if ($locationId !== null && $headquartersId !== null && !isset($errors['locationHeadquartersId'])) {
            $office = $this->repository->locationHeadquarters($headquartersId);
            if ($office === null || (int) $office['location_id'] !== $locationId) {
                $errors['locationHeadquartersId'] = 'Choose a headquarters in the selected state.';
            }
        }
        $employeeId = $current === null ? null : (int) $current['id'];
        $managers = $this->managerAssignments($input, $designation, $employeeId, $current, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $now = UtcTime::now();

        return [
            'fields' => [
                'name' => $name,
                'employee_code' => $code,
                'phone' => $phone,
                'email' => $email,
                'designation' => $designation,
                'location_id' => $locationId,
                'location_headquarters_id' => $headquartersId,
                'updated_at' => $now,
            ] + ($current === null ? ['created_at' => $now] : []),
            'managers' => $managers,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @param array<string, string> $errors
     */
    private function textValue(array $input, string $field, string $label, int $max, string $column, ?array $current, array &$errors): ?string
    {
        if ($current !== null && !array_key_exists($field, $input)) {
            $value = $current[$column] ?? null;

            return $value === null ? null : (string) $value;
        }

        return Input::optionalText($input, $field, $label, $max, $errors);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @param array<string, string> $errors
     */
    private function designation(array $input, ?array $current, array &$errors): ?string
    {
        if ($current !== null && !array_key_exists('designation', $input)) {
            $stored = $current['designation'] ?? null;

            return $stored === null || $stored === '' ? null : (string) $stored;
        }
        if (!array_key_exists('designation', $input) || $input['designation'] === null || $input['designation'] === '') {
            return null;
        }
        if (!is_string($input['designation']) || !isset(self::DESIGNATIONS[$input['designation']])) {
            $errors['designation'] = 'Choose a designation from the list.';

            return null;
        }

        return $input['designation'];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @param array<string, string> $errors
     * @param callable(int): bool $exists
     */
    private function foreignId(array $input, string $field, string $column, ?array $current, array &$errors, string $message, callable $exists): ?int
    {
        if ($current !== null && !array_key_exists($field, $input)) {
            return isset($current[$column]) && $current[$column] !== null ? (int) $current[$column] : null;
        }

        return $this->optionalForeignId($input, $field, $errors, $message, $exists);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     * @param callable(int): bool $exists
     */
    private function optionalForeignId(array $input, string $field, array &$errors, string $message, callable $exists): ?int
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            return null;
        }
        $id = Input::id($input[$field]);
        if ($id === null || !$exists($id)) {
            $errors[$field] = $message;

            return null;
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @param array<string, string> $errors
     * @return array<string, int>
     */
    private function managerAssignments(array $input, ?string $designation, ?int $employeeId, ?array $current, array &$errors): array
    {
        if (!array_key_exists('managers', $input) || $input['managers'] === null) {
            return $this->keptManagers($current, $designation);
        }
        $managers = $input['managers'];
        // An empty JSON object arrives as []. A non-empty list is not a designation map.
        if (!is_array($managers) || ($managers !== [] && array_is_list($managers))) {
            $errors['managers'] = 'Manager assignments must be a set of designations.';

            return [];
        }
        if ($managers === []) {
            return [];
        }
        $above = array_flip(self::above($designation));
        $assigned = [];
        foreach ($managers as $code => $value) {
            $code = (string) $code;
            $field = 'manager' . $code;
            if (!isset(self::DESIGNATIONS[$code])) {
                $errors[$field] = 'Choose a designation from the list.';
                continue;
            }
            if (!isset($above[$code])) {
                if ($value !== null && $value !== '') {
                    $errors[$field] = 'This designation is not above the employee’s designation.';
                }
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $managerId = Input::id($value);
            if ($managerId === null) {
                $errors[$field] = 'Choose an employee from the list.';
                continue;
            }
            if ($employeeId !== null && $managerId === $employeeId) {
                $errors[$field] = 'An employee cannot be their own manager.';
                continue;
            }
            $manager = $this->repository->find($managerId);
            if ($manager === null || $manager['designation'] !== $code) {
                $errors[$field] = 'Choose an employee with this designation.';
                continue;
            }
            $assigned[$code] = $managerId;
        }

        return $assigned;
    }

    /**
     * @param array<string, mixed>|null $current
     * @return array<string, int>
     */
    private function keptManagers(?array $current, ?string $designation): array
    {
        if ($current === null) {
            return [];
        }
        $above = array_flip(self::above($designation));
        $kept = [];
        foreach ($current['managers'] ?? [] as $link) {
            $code = (string) $link['manager_designation'];
            if (isset($above[$code])) {
                $kept[$code] = (int) $link['manager_employee_id'];
            }
        }

        return $kept;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        $managers = [];
        foreach (self::DESIGNATIONS as $code => $label) {
            foreach ($row['managers'] ?? [] as $link) {
                if ($link['manager_designation'] !== $code) {
                    continue;
                }
                $managers[] = [
                    'designation' => $code,
                    'designationLabel' => $label,
                    'employeeId' => (int) $link['manager_employee_id'],
                    'employeeName' => $link['manager_name'],
                    'employeeCode' => $link['manager_code'],
                ];
            }
        }

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'employeeCode' => $row['employee_code'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'designation' => $row['designation'],
            'designationLabel' => $row['designation'] === null ? null : (self::DESIGNATIONS[$row['designation']] ?? null),
            'locationId' => $row['location_id'] === null ? null : (int) $row['location_id'],
            'locationName' => $row['location_name'] ?? null,
            'locationHeadquartersId' => $row['location_headquarters_id'] === null ? null : (int) $row['location_headquarters_id'],
            'locationHeadquartersName' => $row['headquarters_name'] ?? null,
            'locationHeadquartersCode' => $row['headquarters_code'] ?? null,
            'managers' => $managers,
            'updatedAt' => UtcTime::iso($row['updated_at']),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function label(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $code = trim((string) ($row['employee_code'] ?? ''));

        return $code !== '' ? $code : 'Employee';
    }
}
