<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SalesOfficeRepository;
use App\Utils\HttpException;

/** Validation and writes for headquarters, the manager hierarchy and stockists. */
final class SalesOfficeService
{
    public function __construct(private readonly SalesOfficeRepository $records = new SalesOfficeRepository())
    {
    }

    /** @param array<string, mixed> $input */
    public function createHeadquarters(array $input): array
    {
        $fields = $this->headquartersFields($input, null);
        $id = $this->records->insertHeadquarters($fields);
        $created = $this->records->findHeadquarters($id);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'create', 'targetType' => 'headquarters', 'targetId' => $id, 'targetLabel' => $created['name'] ?? null]);
        EntityHistory::recordDiff('headquarters', $id, $created['name'] ?? null, [], ['Name' => $created['name'] ?? null, 'Code' => $created['code'] ?? null]);

        return ErpPresenter::headquarters($created);
    }

    /** @param array<string, mixed> $input */
    public function updateHeadquarters(int $id, array $input): array
    {
        $this->requireHeadquarters($id);
        $before = $this->records->findHeadquarters($id);
        $this->records->updateHeadquarters($id, $this->headquartersFields($input, $id));
        $updated = $this->records->findHeadquarters($id);
        EntityHistory::recordDiff('headquarters', $id, $updated['name'] ?? null, ['Name' => $before['name'] ?? null, 'Code' => $before['code'] ?? null], ['Name' => $updated['name'] ?? null, 'Code' => $updated['code'] ?? null]);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'update', 'targetType' => 'headquarters', 'targetId' => $id, 'targetLabel' => $updated['name'] ?? null]);

        return ErpPresenter::headquarters($updated);
    }

    /** @return list<array<string, mixed>> */
    public function listedLocations(): array
    {
        $grouped = $this->records->locationHeadquartersGrouped();

        return array_map(
            fn (array $row): array => ErpPresenter::location($row, $grouped[(int) $row['id']] ?? []),
            $this->records->locations(),
        );
    }

    /** @param array<string, mixed> $input */
    public function createLocationHeadquarters(array $input): array
    {
        $fields = $this->locationHeadquartersFields($input, null);
        $lhId = $this->records->insertLocationHeadquarters($fields);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'create', 'targetType' => 'location_headquarters', 'targetId' => is_int($lhId) ? $lhId : null, 'targetLabel' => $fields['name'] ?? null]);

        return $this->listedLocations();
    }

    /** @param array<string, mixed> $input */
    public function updateLocationHeadquarters(int $id, array $input): array
    {
        $this->records->findLocationHeadquarters($id) ?? throw HttpException::notFound('Location headquarters not found.');
        $fields = $this->locationHeadquartersFields($input, $id);
        $location = $this->records->findLocation((int) $fields['location_id']);
        $this->records->updateLocationHeadquarters($id, $fields);
        $this->records->copyLocationOfficeOntoHeadquarters($id, [
            'name' => $fields['name'],
            'state' => $location['name'] ?? '',
            'location_id' => $fields['location_id'],
            'address' => $fields['address'],
            'pincode' => $fields['pincode'],
            'phone' => $fields['phone'],
            'email' => $fields['email'],
            'code' => $fields['code'],
        ]);

        return $this->listedLocations();
    }

    public function deleteLocationHeadquarters(int $id): void
    {
        $this->records->findLocationHeadquarters($id) ?? throw HttpException::notFound('Location headquarters not found.');
        if ($this->records->findHeadquartersByLocationOffice($id) !== null) {
            $message = 'This location headquarters is linked to a headquarters office and cannot be deleted.';
            throw HttpException::validation(['name' => $message], $message);
        }
        $current = $this->records->findLocationHeadquarters($id);
        $this->records->deleteLocationHeadquarters($id);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'delete', 'targetType' => 'location_headquarters', 'targetId' => $id, 'targetLabel' => $current['name'] ?? null]);
    }

    /** @param array<string, mixed> $input */
    public function createLocation(array $input): array
    {
        $id = $this->records->insertLocation($this->locationFields($input, null));
        $created = $this->records->findLocation($id);
        EntityHistory::recordDiff('location', $id, $created['name'] ?? null, [], ['Name' => $created['name'] ?? null, 'Code' => $created['code'] ?? null]);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'create', 'targetType' => 'location', 'targetId' => $id, 'targetLabel' => $created['name'] ?? null]);

        return ErpPresenter::location($created);
    }

    /** @param array<string, mixed> $input */
    public function updateLocation(int $id, array $input): array
    {
        $before = $this->records->findLocation($id) ?? throw HttpException::notFound('State not found.');
        $this->records->updateLocation($id, $this->locationFields($input, $id));
        $updated = $this->records->findLocation($id);
        EntityHistory::recordDiff('location', $id, $updated['name'] ?? null, ['Name' => $before['name'] ?? null, 'Code' => $before['code'] ?? null], ['Name' => $updated['name'] ?? null, 'Code' => $updated['code'] ?? null]);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'update', 'targetType' => 'location', 'targetId' => $id, 'targetLabel' => $updated['name'] ?? null]);

        return ErpPresenter::location($updated);
    }

    public function deleteLocation(int $id): void
    {
        $this->records->findLocation($id) ?? throw HttpException::notFound('State not found.');
        if ($this->records->assignmentCount($id) > 0) {
            $message = 'This state is assigned to a 3rd line manager and cannot be deleted.';
            throw HttpException::validation(['name' => $message], $message);
        }
        if ($this->records->headquartersForLocationCount($id) > 0) {
            $message = 'This state is assigned to a headquarters and cannot be deleted.';
            throw HttpException::validation(['name' => $message], $message);
        }
        $current = $this->records->findLocation($id);
        $this->records->deleteLocation($id);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'delete', 'targetType' => 'location', 'targetId' => $id, 'targetLabel' => $current['name'] ?? null]);
    }

    public function deleteHeadquarters(int $id): void
    {
        $this->requireHeadquarters($id);
        $current = $this->records->findHeadquarters($id);
        $this->records->deleteHeadquarters($id);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'delete', 'targetType' => 'headquarters', 'targetId' => $id, 'targetLabel' => $current['name'] ?? null]);
    }

    public function deleteStockist(int $id): void
    {
        if ($this->records->findStockist($id) === null) {
            throw HttpException::notFound('Stockist not found.');
        }
        $current = $this->records->findStockist($id);
        $this->records->deleteStockist($id);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'delete', 'targetType' => 'stockist', 'targetId' => $id, 'targetLabel' => $current['name'] ?? null]);
    }

    /** @param array<string, mixed> $input @return array{name: string, code: string} */
    private function locationFields(array $input, ?int $currentId): array
    {
        $errors = [];
        Input::onlyFields($input, ['name', 'code'], $errors);
        $name = Input::text($input, 'name', 'state name', 100, $errors);
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        if ($code === '') {
            $errors['code'] = 'Enter the location code.';
        } elseif (strlen($code) > 20 || preg_match('/^[A-Z0-9][A-Z0-9-]{0,19}$/', $code) !== 1) {
            $errors['code'] = 'Use a short code of letters and numbers.';
        }
        $this->fail($errors);
        $byName = $this->records->findLocationByName($name);
        if ($byName !== null && ($currentId === null || (int) $byName['id'] !== $currentId)) {
            throw HttpException::validation(['name' => 'This state already exists.']);
        }
        $byCode = $this->records->findLocationByCode($code);
        if ($byCode !== null && ($currentId === null || (int) $byCode['id'] !== $currentId)) {
            throw HttpException::validation(['code' => 'This location code is already in use.']);
        }

        return ['name' => $name, 'code' => $code];
    }

    public function deleteManager(int $id): void
    {
        $manager = $this->records->findManager($id) ?? throw HttpException::notFound('Manager not found.');
        $level = (int) $manager['level'];
        if ($level === 1 && $this->records->headquartersForManagerCount($id) > 0) {
            $message = 'This 1st line manager has headquarters and cannot be deleted.';
            throw HttpException::validation(['manager' => $message], $message);
        }
        if ($level !== 1 && $this->records->childManagerCount($id) > 0) {
            $message = $level === 3
                ? 'This 3rd line manager has 2nd line managers and cannot be deleted.'
                : 'This 2nd line manager has 1st line managers and cannot be deleted.';
            throw HttpException::validation(['manager' => $message], $message);
        }
        $this->records->deleteManager($id);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'delete', 'targetType' => 'manager', 'targetId' => $id, 'targetLabel' => $manager['name'] ?? null]);
    }

    /** @return list<array<string, mixed>> */
    public function listedManagers(): array
    {
        $grouped = $this->records->statesGrouped();

        return array_map(
            static fn (array $row): array => ErpPresenter::manager($row, $grouped[(int) $row['id']] ?? []),
            $this->records->managers(),
        );
    }

    /** @param array<string, mixed> $input */
    public function createManager(array $input): array
    {
        [$fields, $states] = $this->managerFields($input, null);
        $id = $this->records->transaction(function () use ($fields, $states): int {
            $id = $this->records->insertManager($fields);
            if ($states !== null) {
                $this->records->replaceManagerStates($id, $states);
            }

            return $id;
        });
        $presented = $this->presentManager($id);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'create', 'targetType' => 'manager', 'targetId' => $id, 'targetLabel' => $presented['name'] ?? null, 'details' => ['level' => $presented['level'] ?? null]]);
        EntityHistory::recordDiff('manager', $id, $presented['name'] ?? null, [], ['Name' => $presented['name'] ?? null, 'Code' => $presented['code'] ?? null, 'Level' => $presented['level'] ?? null]);

        return $presented;
    }

    /** @param array<string, mixed> $input */
    public function updateManager(int $id, array $input): array
    {
        $current = $this->records->findManager($id) ?? throw HttpException::notFound('Manager not found.');
        [$fields, $states] = $this->managerFields($input, $current);
        $this->records->transaction(function () use ($id, $fields, $states): void {
            $this->records->updateManager($id, $fields);
            if ($states !== null) {
                $this->records->replaceManagerStates($id, $states);
            }
        });
        $presented = $this->presentManager($id);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'update', 'targetType' => 'manager', 'targetId' => $id, 'targetLabel' => $presented['name'] ?? null]);

        return $presented;
    }

    /** @param array<string, mixed> $input */
    public function createStockist(array $input): array
    {
        $id = $this->records->insertStockist($this->stockistFields($input));
        $created = $this->records->findStockist($id);
        EntityHistory::recordDiff('stockist', $id, $created['name'] ?? null, [], ['Name' => $created['name'] ?? null, 'Code' => $created['code'] ?? null, 'Headquarters' => $created['headquarters_id'] ?? null]);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'create', 'targetType' => 'stockist', 'targetId' => $id, 'targetLabel' => $created['name'] ?? null]);

        return ErpPresenter::stockist($created);
    }

    /** @param array<string, mixed> $input */
    public function updateStockist(int $id, array $input): array
    {
        $before = $this->records->findStockist($id) ?? throw HttpException::notFound('Stockist not found.');
        $this->records->updateStockist($id, $this->stockistFields($input));
        $updated = $this->records->findStockist($id);
        EntityHistory::recordDiff('stockist', $id, $updated['name'] ?? null, ['Name' => $before['name'] ?? null, 'Code' => $before['code'] ?? null, 'Headquarters' => $before['headquarters_id'] ?? null], ['Name' => $updated['name'] ?? null, 'Code' => $updated['code'] ?? null, 'Headquarters' => $updated['headquarters_id'] ?? null]);
        AuditLogger::record(['module' => 'sales_office', 'action' => 'update', 'targetType' => 'stockist', 'targetId' => $id, 'targetLabel' => $updated['name'] ?? null]);

        return ErpPresenter::stockist($updated);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, state: string, address: string, pincode: string, phone: string, code: ?string, parent_manager_id: int}
     */
    /**
     * @param array<string, mixed> $input
     * @return array{location_id: int, name: string, code: string, address: string, pincode: string, phone: string, email: string, position: int}
     */
    private function locationHeadquartersFields(array $input, ?int $currentId): array
    {
        $errors = [];
        Input::onlyFields($input, ['locationId', 'name', 'code', 'address', 'pincode', 'phone', 'email'], $errors);
        $locationId = Input::id($input['locationId'] ?? null);
        $name = Input::text($input, 'name', 'headquarters name', 150, $errors);
        $location = $locationId === null ? null : $this->records->findLocation($locationId);
        if ($location === null) {
            $errors['locationId'] = 'Choose a state from the list.';
        }
        $current = $currentId === null ? null : $this->records->findLocationHeadquarters($currentId);
        $rawCode = trim((string) ($input['code'] ?? ''));
        $code = null;
        if ($rawCode === '' && $current !== null && ($current['code'] ?? '') !== '') {
            $code = (string) $current['code'];
        } elseif (preg_match('/^HQ-([0-9]+)$/i', $rawCode, $matches) === 1) {
            $code = 'HQ-' . $matches[1];
        } else {
            $errors['code'] = 'Enter numbers only after HQ-.';
        }
        $address = Input::text($input, 'address', 'address', 255, $errors);
        $pincode = self::digits($input['pincode'] ?? null);
        $phone = self::requiredPhone($input['phone'] ?? null, $errors);
        if ($pincode === null || !preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
            $errors['pincode'] = 'Enter a 6-digit pincode.';
        }
        $email = UserValidator::email($input['email'] ?? null, $errors);
        if ($locationId !== null && $name !== null) {
            foreach ($this->records->locationHeadquartersGrouped()[$locationId] ?? [] as $office) {
                if ($currentId !== null && (int) $office['id'] === $currentId) {
                    continue;
                }
                if (mb_strtolower((string) $office['name']) === mb_strtolower($name)) {
                    $errors['name'] = 'This headquarters name already exists for the state.';
                }
            }
        }
        $this->fail($errors);
        $sameCode = $this->records->findLocationHeadquartersByCode((string) $code);
        if ($sameCode !== null && ($currentId === null || (int) $sameCode['id'] !== $currentId)) {
            throw HttpException::validation(['code' => 'This code is already in use.']);
        }
        $officeCode = $this->records->findHeadquartersByCode((string) $code);
        $linked = $currentId === null ? null : $this->records->findHeadquartersByLocationOffice($currentId);
        if ($officeCode !== null && ($linked === null || (int) $officeCode['id'] !== (int) $linked['id'])) {
            throw HttpException::validation(['code' => 'This code is already in use.']);
        }
        $position = $currentId === null
            ? count($this->records->locationHeadquartersGrouped()[$locationId] ?? []) + 1
            : (int) ($current['position'] ?? 1);

        return [
            'location_id' => $locationId,
            'name' => $name,
            'code' => $code,
            'address' => $address,
            'pincode' => $pincode,
            'phone' => $phone,
            'email' => $email,
            'position' => $position,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function headquartersFromLocationOffice(array $input, ?int $currentId): array
    {
        $errors = [];
        Input::onlyFields($input, ['locationHeadquartersId', 'parentManagerId'], $errors);
        $officeId = Input::id($input['locationHeadquartersId'] ?? null);
        $office = $officeId === null ? null : $this->records->findLocationHeadquarters($officeId);
        if ($office === null) {
            $errors['locationHeadquartersId'] = 'Choose a headquarters from the location list.';
        } elseif ($office['code'] === null || $office['address'] === null || $office['pincode'] === null || $office['phone'] === null || $office['email'] === null) {
            $errors['locationHeadquartersId'] = 'Complete this location headquarters on the Location page before linking it.';
        }
        $parentId = Input::id($input['parentManagerId'] ?? null);
        if ($parentId === null) {
            $errors['parentManagerId'] = 'Select the 1st line manager this headquarters belongs to.';
        }
        $this->fail($errors);
        $parent = $this->records->findManager($parentId);
        if ($parent === null || (int) $parent['level'] !== 1) {
            throw HttpException::validation(['parentManagerId' => 'That 1st line manager does not exist.']);
        }
        $taken = $this->records->findHeadquartersByLocationOffice((int) $office['id']);
        if ($taken !== null && ($currentId === null || (int) $taken['id'] !== $currentId)) {
            throw HttpException::validation(['locationHeadquartersId' => 'This location headquarters is already linked to a headquarters office.']);
        }
        $sameCode = $this->records->findHeadquartersByCode((string) $office['code']);
        if ($sameCode !== null && ($currentId === null || (int) $sameCode['id'] !== $currentId)) {
            throw HttpException::validation(['locationHeadquartersId' => 'This code is already in use.']);
        }

        return [
            'name' => $office['name'],
            'state' => $office['location_name'],
            'location_id' => (int) $office['location_id'],
            'location_headquarters_id' => (int) $office['id'],
            'address' => $office['address'],
            'pincode' => $office['pincode'],
            'phone' => $office['phone'],
            'email' => $office['email'],
            'code' => $office['code'],
            'parent_manager_id' => $parentId,
        ];
    }

    private function headquartersFields(array $input, ?int $currentId): array
    {
        if (array_key_exists('locationHeadquartersId', $input)) {
            return $this->headquartersFromLocationOffice($input, $currentId);
        }
        $errors = [];
        Input::onlyFields($input, ['name', 'state', 'address', 'pincode', 'phone', 'code', 'parentManagerId', 'locationId', 'email'], $errors);
        $name = Input::text($input, 'name', 'headquarters name', 150, $errors);
        $linked = array_key_exists('locationId', $input);
        $locationId = null;
        if ($linked) {
            $locationId = Input::id($input['locationId'] ?? null);
            $location = $locationId === null ? null : $this->records->findLocation($locationId);
            if ($location === null) {
                $errors['locationId'] = 'Choose a state from the list.';
                $state = null;
            } else {
                $state = $location['name'];
            }
        } else {
            $state = Input::text($input, 'state', 'state', 100, $errors);
            if ($state !== null && !in_array($state, self::states(), true)) {
                $errors['state'] = 'Choose a state or union territory from the list.';
            } elseif ($state !== null) {
                $matched = $this->records->findLocationByName($state);
                if ($matched !== null) {
                    $locationId = (int) $matched['id'];
                }
            }
        }
        $address = Input::text($input, 'address', 'address', 255, $errors);
        $pincode = self::digits($input['pincode'] ?? null);
        $phone = self::requiredPhone($input['phone'] ?? null, $errors);
        if ($pincode === null || !preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
            $errors['pincode'] = 'Enter a 6-digit pincode.';
        }
        $code = Input::optionalText($input, 'code', 'code', 50, $errors);
        if ($linked && $code !== null && preg_match('/^HQ-/i', $code) === 1 && preg_match('/^HQ-([0-9]+)$/i', $code, $matches) !== 1) {
            $errors['code'] = 'Enter numbers only after HQ-.';
            $code = null;
        } elseif ($code !== null && preg_match('/^HQ-([0-9]+)$/i', $code, $matches) === 1) {
            $code = 'HQ-' . $matches[1];
        }
        if ($linked && ($code === null || $code === '') && !isset($errors['code'])) {
            $errors['code'] = 'Enter the headquarters code.';
        }
        $writeEmail = $linked || array_key_exists('email', $input);
        $email = null;
        if ($writeEmail) {
            $rawEmail = $input['email'] ?? null;
            if ($rawEmail === null || (is_string($rawEmail) && trim($rawEmail) === '')) {
                if ($linked) {
                    $errors['email'] = 'Enter an email address.';
                }
            } else {
                $email = UserValidator::email($rawEmail, $errors);
            }
        }
        $parentId = Input::id($input['parentManagerId'] ?? null);
        if ($parentId === null) {
            $errors['parentManagerId'] = 'Select the 1st line manager this headquarters belongs to.';
        }
        $this->fail($errors);
        $parent = $this->records->findManager($parentId);
        if ($parent === null || (int) $parent['level'] !== 1) {
            throw HttpException::validation(['parentManagerId' => 'That 1st line manager does not exist.']);
        }

        $fields = [
            'name' => $name,
            'state' => $state,
            'address' => $address,
            'pincode' => $pincode,
            'phone' => $phone,
            'code' => $code,
            'parent_manager_id' => $parentId,
        ];
        if ($locationId !== null || $linked) {
            $fields['location_id'] = $locationId;
        }
        if ($writeEmail) {
            $fields['email'] = $email;
        }

        return $fields;
    }

    private static function digits(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * Codes that start with ER-M must be `ER-M{level}-` plus digits. The digits
     * are kept as text. Any other code is left unchanged so existing rows stay valid.
     *
     * @param array<string, string> $errors
     */
    private static function employeeCode(?string $code, mixed $level, array &$errors): ?string
    {
        if ($code === null || $code === '' || preg_match('/^ER-M/i', $code) !== 1) {
            return $code;
        }
        if (preg_match('/^ER-M([123])-([0-9]+)$/i', $code, $matches) !== 1 || (int) $matches[1] !== (int) $level) {
            $prefix = 'ER-M' . (int) $level . '-';
            $errors['code'] = "Enter numbers only after {$prefix}.";

            return null;
        }

        return 'ER-M' . $matches[1] . '-' . $matches[2];
    }

    /** Empty email is stored as null. A provided address is checked and stored lower-case. */
    private static function optionalEmail(mixed $value, array &$errors): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return UserValidator::email($value, $errors);
    }

    /**
     * Empty phone is stored as null. A provided number is kept as text, including
     * a leading zero or a country code.
     *
     * @param array<string, string> $errors
     */
    private static function optionalPhone(mixed $value, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            $errors['phone'] = 'Enter a phone number.';

            return null;
        }
        $phone = trim($value);
        if ($phone === '') {
            return null;
        }
        if (strlen($phone) > 20 || preg_match('/^\+?[0-9][0-9\s().-]{5,19}$/', $phone) !== 1) {
            $errors['phone'] = 'Enter a valid phone number.';

            return null;
        }

        return $phone;
    }

    /** @param array<string, string> $errors */
    private static function requiredPhone(mixed $value, array &$errors): ?string
    {
        $phone = self::optionalPhone($value, $errors);
        if ($phone === null && !isset($errors['phone'])) {
            $errors['phone'] = 'Enter a phone number.';
        }

        return $phone;
    }

    /** @return list<string> */
    private static function states(): array
    {
        return require dirname(__DIR__) . '/config/states.php';
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @return array{0: array<string, mixed>, 1: list<string>|null}
     */
    private function managerFields(array $input, ?array $current): array
    {
        $errors = [];
        Input::onlyFields($input, ['name', 'code', 'email', 'phone', 'level', 'parentType', 'parentId', 'stateIds'], $errors);
        $name = Input::text($input, 'name', 'name', 150, $errors);
        $code = self::employeeCode(Input::optionalText($input, 'code', 'code', 50, $errors), $input['level'] ?? null, $errors);
        $level = $input['level'] ?? null;
        if (!in_array($level, [1, 2, 3], true)) {
            $errors['level'] = 'Manager level must be 1, 2 or 3.';
        }
        if ($current !== null && (int) $level !== (int) $current['level']) {
            $errors['level'] = 'A manager’s level cannot be changed.';
        }
        $parentType = $input['parentType'] ?? '';
        $parentId = Input::id($input['parentId'] ?? null);
        $parentHq = null;
        $parentManager = null;
        $stateIds = $this->managerStateIds($input, (int) $level, $errors);
        $writeEmail = $stateIds !== null || array_key_exists('email', $input);
        $writePhone = $stateIds !== null || array_key_exists('phone', $input);
        $email = $writeEmail ? self::optionalEmail($input['email'] ?? null, $errors) : null;
        $phone = $writePhone ? self::optionalPhone($input['phone'] ?? null, $errors) : null;
        if ($stateIds !== null) {
            if ((int) $level === 3 && ($code === null || $code === '') && !isset($errors['code'])) {
                $errors['code'] = 'Enter the employee code.';
            }
            if (((int) $level === 1 || (int) $level === 2) && $stateIds === [] && !isset($errors['stateIds'])) {
                $errors['stateIds'] = 'Select at least one state.';
            }
        }
        if ((int) $level === 3) {
            if ($parentType !== '' || $parentId !== null) {
                $errors['parentType'] = 'A 3rd line manager is at the top of the hierarchy and has no parent.';
            }
        } else {
            $allowed = [1 => ['manager2'], 2 => ['manager3']][(int) $level] ?? [];
            if (!in_array($parentType, $allowed, true)) {
                $errors['parentType'] = 'Choose the manager at the level directly above. A level cannot be skipped.';
            }
            if ($parentId === null) {
                $errors['parentId'] = 'Select the manager this record belongs to.';
            }
        }
        $this->fail($errors);

        if ((int) $level !== 3) {
            $parent = $this->records->findManager($parentId);
            $expected = (int) substr((string) $parentType, -1);
            if ($parent === null || (int) $parent['level'] !== $expected) {
                throw HttpException::validation(['parentId' => 'That manager is not a valid parent for this level.']);
            }
            if ($current !== null && (int) $parent['id'] === (int) $current['id']) {
                throw HttpException::validation(['parentId' => 'A manager cannot report to themselves.']);
            }
            $parentManager = $parentId;
        }

        if (((int) $level === 1 || (int) $level === 2) && $stateIds !== null && $stateIds !== []) {
            $allowed = array_map(
                static fn (array $state): int => (int) $state['id'],
                $this->records->statesGrouped()[(int) $parentManager] ?? [],
            );
            if (array_diff($stateIds, $allowed) !== []) {
                $parentLevel = (int) $level === 1 ? '2nd' : '3rd';
                throw HttpException::validation(['stateIds' => "Choose a state assigned to this {$parentLevel} line manager."]);
            }
        }

        $fields = [
            'level' => (int) $level,
            'name' => $name,
            'code' => $code,
            'parent_headquarters_id' => $parentHq,
            'parent_manager_id' => $parentManager,
        ];
        if ($writeEmail) {
            $fields['email'] = $email;
        }
        if ($writePhone) {
            $fields['phone'] = $phone;
        }

        return [$fields, $stateIds];
    }

    /**
     * Null when the request does not mention stateIds, so older clients leave assignments alone.
     * The 3rd line manager form always sends the list.
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     * @return list<int>|null
     */
    private function managerStateIds(array $input, int $level, array &$errors): ?array
    {
        if (!array_key_exists('stateIds', $input)) {
            return null;
        }
        $ids = Input::idList($input['stateIds']);
        if ($ids === null) {
            $errors['stateIds'] = 'Choose a state from the list.';

            return [];
        }
        if ($level === 3 && $ids === []) {
            $errors['stateIds'] = 'Select at least one state.';
        }
        if ($ids !== [] && count($this->records->existingLocationIds($ids)) !== count($ids)) {
            $errors['stateIds'] = 'Choose a state from the list.';
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function presentManager(int $id): array
    {
        return ErpPresenter::manager(
            $this->records->findManager($id),
            $this->records->statesGrouped()[$id] ?? [],
        );
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function stockistFields(array $input): array
    {
        $errors = [];
        Input::onlyFields($input, ['name', 'code', 'headquartersId', 'address', 'pincode', 'phone', 'email'], $errors);
        $name = Input::text($input, 'name', 'stockist name', 150, $errors);
        $code = Input::optionalText($input, 'code', 'code', 50, $errors);
        if ($code !== null && preg_match('/^ST-/i', $code) === 1) {
            if (preg_match('/^ST-([0-9]+)$/i', $code, $matches) !== 1) {
                $errors['code'] = 'Enter numbers only after ST-.';
                $code = null;
            } else {
                $code = 'ST-' . $matches[1];
            }
        }
        $hqId = Input::id($input['headquartersId'] ?? null);
        if ($hqId === null) {
            $errors['headquartersId'] = 'Select a headquarters.';
        }
        $fields = ['name' => $name, 'code' => $code, 'headquarters_id' => $hqId];
        if (array_key_exists('address', $input)) {
            $fields['address'] = Input::optionalText($input, 'address', 'address', 255, $errors);
        }
        if (array_key_exists('pincode', $input)) {
            $pincode = self::digits($input['pincode'] ?? null);
            if ($pincode !== null && preg_match('/^[1-9][0-9]{5}$/', $pincode) !== 1) {
                $errors['pincode'] = 'Enter a 6-digit pincode.';
            }
            $fields['pincode'] = isset($errors['pincode']) ? null : $pincode;
        }
        if (array_key_exists('phone', $input)) {
            $fields['phone'] = self::optionalPhone($input['phone'] ?? null, $errors);
        }
        if (array_key_exists('email', $input)) {
            $fields['email'] = self::optionalEmail($input['email'] ?? null, $errors);
        }
        $this->fail($errors);
        if ($this->records->findHeadquarters($hqId) === null) {
            throw HttpException::validation(['headquartersId' => 'That headquarters does not exist.']);
        }

        return $fields;
    }

    private function requireHeadquarters(int $id): void
    {
        if ($this->records->findHeadquarters($id) === null) {
            throw HttpException::notFound('Headquarters not found.');
        }
    }

    /** @param array<string, string> $errors */
    private function fail(array $errors): void
    {
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
    }
}
