<?php

declare(strict_types=1);

namespace App\Repositories;

/** The employee list used as announcement recipients (maintained in admin). */
final class EmployeeRepository extends Repository
{
    private const COLUMNS = 'e.id, e.name, e.email, e.phone, e.status, e.location_id, e.headquarters_id, e.role, e.created_at, e.updated_at,
        l.name AS location_name, h.name AS headquarters_name, h.code AS headquarters_code';

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->many(
            'SELECT ' . self::COLUMNS . ' FROM employees e
             LEFT JOIN locations l ON l.id = e.location_id
             LEFT JOIN headquarters h ON h.id = e.headquarters_id
             WHERE e.deleted_at IS NULL ORDER BY e.name, e.id'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one(
            'SELECT ' . self::COLUMNS . ' FROM employees e
             LEFT JOIN locations l ON l.id = e.location_id
             LEFT JOIN headquarters h ON h.id = e.headquarters_id
             WHERE e.id = ? AND e.deleted_at IS NULL',
            [$id]
        );
    }

    public function insert(array $fields): int
    {
        return $this->run(fn (): int => $this->insertRow('employees', $fields), ['employees_email_unique' => ['email' => 'Another employee already uses this email.']]);
    }

    public function update(int $id, array $fields): void
    {
        $this->run(fn () => $this->updateRow('employees', $id, $fields), ['employees_email_unique' => ['email' => 'Another employee already uses this email.']]);
    }

    /** Soft delete; the email is freed so it can be used again. */
    public function softDelete(int $id, int $userId): void
    {
        $this->pdo()->prepare('UPDATE employees SET deleted_at = UTC_TIMESTAMP(), email = NULL, status = \'inactive\', updated_by = ? WHERE id = ?')->execute([$userId, $id]);
    }

    /** @return list<string> */
    public function roles(): array
    {
        return array_column($this->many("SELECT DISTINCT role FROM employees WHERE deleted_at IS NULL AND role IS NOT NULL AND role <> '' ORDER BY role"), 'role');
    }

    public function locationExists(int $id): bool
    {
        return $this->exists('SELECT 1 FROM locations WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function headquarters(int $id): ?array
    {
        return $this->one('SELECT id, location_id FROM headquarters WHERE id = ?', [$id]);
    }
}