<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EmployeeRepository;
use App\Utils\HttpException;

/** The employee list (announcement recipients; later also employee-app sign-in). */
final class EmployeeService
{
    public function __construct(private readonly EmployeeRepository $repository = new EmployeeRepository())
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map([self::class, 'present'], $this->repository->all());
    }

    public function create(array $input, array $user): array
    {
        $values = $this->validate($input);
        $id = $this->repository->insert($values + ['created_by' => (int) $user['id'], 'updated_by' => (int) $user['id'], 'created_at' => UtcTime::now(), 'updated_at' => UtcTime::now()]);

        return self::present($this->repository->find($id));
    }

    public function update(int $id, array $input, array $user): array
    {
        $this->repository->find($id) ?? throw HttpException::notFound('Employee not found.');
        $values = $this->validate($input);
        $this->repository->update($id, $values + ['updated_by' => (int) $user['id'], 'updated_at' => UtcTime::now()]);

        return self::present($this->repository->find($id));
    }

    public function delete(int $id, array $user): void
    {
        $this->repository->find($id) ?? throw HttpException::notFound('Employee not found.');
        $this->repository->softDelete($id, (int) $user['id']);
    }

    /** @return array<string, mixed> */
    private function validate(array $input): array
    {
        $errors = [];
        Input::onlyFields($input, ['name', 'email', 'phone', 'status', 'locationId', 'headquartersId', 'role'], $errors);
        $name = Input::text($input, 'name', 'name', 150, $errors);
        $email = Input::optionalText($input, 'email', 'email', 254, $errors);
        if ($email !== null) {
            $email = mb_strtolower($email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            }
        }
        $phone = Input::optionalText($input, 'phone', 'phone', 20, $errors);
        if ($phone !== null && !preg_match('/^\+?[0-9 ()-]{7,20}$/', $phone)) {
            $errors['phone'] = 'Enter a valid phone number.';
        }
        $status = $input['status'] ?? 'active';
        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors['status'] = 'Choose active or inactive.';
        }
        $role = Input::optionalText($input, 'role', 'role', 100, $errors);
        $locationId = null;
        if (($input['locationId'] ?? null) !== null && $input['locationId'] !== '') {
            $locationId = Input::id($input['locationId']);
            if ($locationId === null || !$this->repository->locationExists($locationId)) {
                $errors['locationId'] = 'Choose a location from the list.';
            }
        }
        $headquartersId = null;
        if (($input['headquartersId'] ?? null) !== null && $input['headquartersId'] !== '') {
            $headquartersId = Input::id($input['headquartersId']);
            $hq = $headquartersId === null ? null : $this->repository->headquarters($headquartersId);
            if ($hq === null) {
                $errors['headquartersId'] = 'Choose a headquarters from the list.';
            } elseif ($locationId === null && $hq['location_id'] !== null) {
                $locationId = (int) $hq['location_id'];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'status' => $status,
            'location_id' => $locationId,
            'headquarters_id' => $headquartersId,
            'role' => $role,
        ];
    }

    /** @return array<string, mixed> */
    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'status' => $row['status'],
            'locationId' => $row['location_id'] === null ? null : (int) $row['location_id'],
            'locationName' => $row['location_name'] ?? null,
            'headquartersId' => $row['headquarters_id'] === null ? null : (int) $row['headquarters_id'],
            'headquartersName' => $row['headquarters_name'] === null ? null : trim($row['headquarters_name'] . ($row['headquarters_code'] ? " ({$row['headquarters_code']})" : '')),
            'role' => $row['role'],
            'updatedAt' => UtcTime::iso($row['updated_at']),
        ];
    }
}