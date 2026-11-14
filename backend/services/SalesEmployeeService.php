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

    public static function fullName(?string $first, ?string $last): ?string
    {
        $parts = [];
        foreach ([$first, $last] as $part) {
            $part = trim($part ?? '');
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
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
        $id = $this->repository->insert($values['fields'], $values['managers'], $values['locationIds'], $values['headquartersIds']);
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
        $this->repository->update($id, $values['fields'], $values['managers'], $values['fields']['designation'], $values['locationIds'], $values['headquartersIds']);
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
     * Headquarters outside the chosen states are dropped when the states change
     * and the headquarters list itself was not sent.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @return array{fields: array<string, mixed>, managers: array<string, int>, locationIds: list<int>, headquartersIds: list<int>}
     */
    private function validate(array $input, ?array $current): array
    {
        $errors = [];
        Input::onlyFields($input, [
            'firstName', 'lastName', 'employeeCode', 'phone', 'email', 'designation',
            'locationIds', 'locationHeadquartersIds', 'managers',
        ], $errors);
        $first = $this->textValue($input, 'firstName', 'first name', 150, 'first_name', $current, $errors);
        $last = $this->textValue($input, 'lastName', 'last name', 150, 'last_name', $current, $errors);
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
        $locationIds = $this->placeIds(
            $input,
            'locationIds',
            $current,
            $errors,
            'Choose a state from the list.',
            $this->repository->locationExists(...),
            static fn (array $row): array => array_map(static fn (array $link): int => (int) $link['location_id'], $row['states'] ?? []),
        );
        $headquartersProvided = array_key_exists('locationHeadquartersIds', $input);
        $headquartersIds = $this->placeIds(
            $input,
            'locationHeadquartersIds',
            $current,
            $errors,
            'Choose a headquarters from the list.',
            fn (int $id): bool => $this->repository->locationHeadquarters($id) !== null,
            static fn (array $row): array => array_map(static fn (array $link): int => (int) $link['location_headquarters_id'], $row['headquarters'] ?? []),
        );
        if (!$headquartersProvided && $current !== null && array_key_exists('locationIds', $input)) {
            $headquartersIds = $this->headquartersInStates($headquartersIds, $locationIds);
        }
        if ($headquartersProvided && !isset($errors['locationHeadquartersIds']) && !isset($errors['locationIds'])) {
            $this->assertHeadquartersInStates($headquartersIds, $locationIds, $errors);
        }
        $employeeId = $current === null ? null : (int) $current['id'];
        $this->assertUnique($code, $phone, $email, $employeeId, $errors);
        $managers = $this->managerAssignments($input, $designation, $employeeId, $current, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $now = UtcTime::now();

        return [
            'fields' => [
                'first_name' => $first,
                'last_name' => $last,
                'name' => self::fullName($first, $last),
                'employee_code' => $code,
                'phone' => $phone,
                'email' => $email,
                'designation' => $designation,
                'location_id' => null,
                'location_headquarters_id' => null,
                'updated_at' => $now,
            ] + ($current === null ? ['created_at' => $now] : []),
            'managers' => $managers,
            'locationIds' => $locationIds,
            'headquartersIds' => $headquartersIds,
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
     * @param callable(array<string, mixed>): list<int> $currentIds
     * @return list<int>
     */
    private function placeIds(array $input, string $field, ?array $current, array &$errors, string $message, callable $exists, callable $currentIds): array
    {
        if ($current !== null && !array_key_exists($field, $input)) {
            return $currentIds($current);
        }
        if (!array_key_exists($field, $input) || $input[$field] === null) {
            return [];
        }
        $ids = Input::idList($input[$field]);
        if ($ids === null) {
            $errors[$field] = $message;

            return [];
        }
        foreach ($ids as $id) {
            if (!$exists($id)) {
                $errors[$field] = $message;

                return [];
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $headquartersIds
     * @param list<int> $locationIds
     * @return list<int>
     */
    private function headquartersInStates(array $headquartersIds, array $locationIds): array
    {
        $allowed = array_flip($locationIds);
        $kept = [];
        foreach ($headquartersIds as $id) {
            $office = $this->repository->locationHeadquarters($id);
            if ($office !== null && isset($allowed[(int) $office['location_id']])) {
                $kept[] = $id;
            }
        }

        return $kept;
    }

    /**
     * @param list<int> $headquartersIds
     * @param list<int> $locationIds
     * @param array<string, string> $errors
     */
    private function assertHeadquartersInStates(array $headquartersIds, array $locationIds, array &$errors): void
    {
        $allowed = array_flip($locationIds);
        foreach ($headquartersIds as $id) {
            $office = $this->repository->locationHeadquarters($id);
            if ($office === null || !isset($allowed[(int) $office['location_id']])) {
                $errors['locationHeadquartersIds'] = 'Choose a headquarters in a selected state.';

                return;
            }
        }
    }

    /** @param array<string, string> $errors */
    private function assertUnique(?string $code, ?string $phone, ?string $email, ?int $exceptId, array &$errors): void
    {
        if ($code !== null && !isset($errors['employeeCode']) && $this->repository->codeTaken($code, $exceptId)) {
            $errors['employeeCode'] = 'This employee code is already used.';
        }
        if ($phone !== null && !isset($errors['phone']) && $this->repository->phoneTaken($phone, $exceptId)) {
            $errors['phone'] = 'This phone number is already used.';
        }
        if ($email !== null && !isset($errors['email']) && $this->repository->emailTaken($email, $exceptId)) {
            $errors['email'] = 'This email is already used.';
        }
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
                    'employeeName' => self::fullName(
                        $link['manager_first_name'] !== null ? (string) $link['manager_first_name'] : null,
                        $link['manager_last_name'] !== null ? (string) $link['manager_last_name'] : null,
                    ) ?? ($link['manager_name'] !== null && $link['manager_name'] !== '' ? (string) $link['manager_name'] : null),
                    'employeeCode' => $link['manager_code'],
                ];
            }
        }

        $locations = [];
        foreach ($row['states'] ?? [] as $link) {
            $locations[] = [
                'id' => (int) $link['location_id'],
                'name' => $link['location_name'],
            ];
        }
        $headquarters = [];
        foreach ($row['headquarters'] ?? [] as $link) {
            $headquarters[] = [
                'id' => (int) $link['location_headquarters_id'],
                'name' => $link['headquarters_name'],
                'code' => $link['headquarters_code'],
                'locationId' => (int) $link['location_id'],
                'locationName' => $link['location_name'],
            ];
        }

        return [
            'id' => (int) $row['id'],
            'firstName' => $row['first_name'],
            'lastName' => $row['last_name'],
            'name' => self::fullName(
                $row['first_name'] !== null ? (string) $row['first_name'] : null,
                $row['last_name'] !== null ? (string) $row['last_name'] : null,
            ),
            'employeeCode' => $row['employee_code'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'designation' => $row['designation'],
            'designationLabel' => $row['designation'] === null ? null : (self::DESIGNATIONS[$row['designation']] ?? null),
            'locationIds' => array_column($locations, 'id'),
            'locations' => $locations,
            'locationHeadquartersIds' => array_column($headquarters, 'id'),
            'headquarters' => $headquarters,
            'managers' => $managers,
            'updatedAt' => UtcTime::iso($row['updated_at']),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function label(array $row): string
    {
        $name = self::fullName(
            isset($row['first_name']) && $row['first_name'] !== null ? (string) $row['first_name'] : null,
            isset($row['last_name']) && $row['last_name'] !== null ? (string) $row['last_name'] : null,
        );
        if ($name === null) {
            $stored = trim((string) ($row['name'] ?? ''));
            $name = $stored === '' ? null : $stored;
        }
        if ($name !== null) {
            return $name;
        }
        $code = trim((string) ($row['employee_code'] ?? ''));

        return $code !== '' ? $code : 'Employee';
    }
}
