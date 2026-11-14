<?php

declare(strict_types=1);

namespace App\Repositories;

/** Sales Office employees and the optional manager assigned at each designation. */
final class SalesEmployeeRepository extends Repository
{
    private const COLUMNS = 'e.id, e.name, e.employee_code, e.phone, e.email, e.designation,
        e.location_id, e.location_headquarters_id, e.created_at, e.updated_at,
        l.name AS location_name, h.name AS headquarters_name, h.code AS headquarters_code';

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->many(
            'SELECT ' . self::COLUMNS . ' FROM sales_employees e
             LEFT JOIN locations l ON l.id = e.location_id
             LEFT JOIN location_headquarters h ON h.id = e.location_headquarters_id
             WHERE e.deleted_at IS NULL
             ORDER BY (e.name IS NULL), e.name, e.id'
        );

        return $this->withManagers($rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->one(
            'SELECT ' . self::COLUMNS . ' FROM sales_employees e
             LEFT JOIN locations l ON l.id = e.location_id
             LEFT JOIN location_headquarters h ON h.id = e.location_headquarters_id
             WHERE e.id = ? AND e.deleted_at IS NULL',
            [$id]
        );

        if ($row === null) {
            return null;
        }

        return $this->withManagers([$row])[0];
    }

    public function locationExists(int $id): bool
    {
        return $this->exists('SELECT 1 FROM locations WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function locationHeadquarters(int $id): ?array
    {
        return $this->one('SELECT id, location_id, name FROM location_headquarters WHERE id = ?', [$id]);
    }

    public function countUsingLocation(int $locationId): int
    {
        return $this->count('SELECT COUNT(*) FROM sales_employees WHERE deleted_at IS NULL AND location_id = ?', [$locationId]);
    }

    public function countUsingHeadquarters(int $headquartersId): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM sales_employees WHERE deleted_at IS NULL AND location_headquarters_id = ?',
            [$headquartersId]
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, int> $managers designation => manager employee id
     */
    public function insert(array $fields, array $managers): int
    {
        return $this->transaction(function () use ($fields, $managers): int {
            $id = $this->insertRow('sales_employees', $fields);
            $this->replaceManagers($id, $managers);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, int> $managers
     */
    public function update(int $id, array $fields, array $managers, ?string $designation): void
    {
        $this->transaction(function () use ($id, $fields, $managers, $designation): void {
            $this->updateRow('sales_employees', $id, $fields);
            $this->replaceManagers($id, $managers);
            $this->clearInboundMismatches($id, $designation);
        });
    }

    public function softDelete(int $id, string $at): void
    {
        $this->transaction(function () use ($id, $at): void {
            $this->pdo()->prepare('DELETE FROM sales_employee_managers WHERE employee_id = ? OR manager_employee_id = ?')->execute([$id, $id]);
            $this->pdo()->prepare(
                'UPDATE sales_employees
                 SET deleted_at = ?, location_id = NULL, location_headquarters_id = NULL, updated_at = ?
                 WHERE id = ?'
            )->execute([$at, $at, $id]);
        });
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function withManagers(array $rows): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $grouped = $this->managerLinks($ids);
        foreach ($rows as $index => $row) {
            $rows[$index]['managers'] = $grouped[(int) $row['id']] ?? [];
        }

        return $rows;
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function managerLinks(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $links = $this->many(
            "SELECT sem.employee_id, sem.manager_designation, sem.manager_employee_id,
                    m.name AS manager_name, m.employee_code AS manager_code
             FROM sales_employee_managers sem
             JOIN sales_employees m ON m.id = sem.manager_employee_id AND m.deleted_at IS NULL
             WHERE sem.employee_id IN ({$marks})
               AND m.designation = sem.manager_designation",
            $ids
        );
        $grouped = [];
        foreach ($links as $link) {
            $grouped[(int) $link['employee_id']][] = $link;
        }

        return $grouped;
    }

    /** @param array<string, int> $managers */
    private function replaceManagers(int $employeeId, array $managers): void
    {
        $this->pdo()->prepare('DELETE FROM sales_employee_managers WHERE employee_id = ?')->execute([$employeeId]);
        if ($managers === []) {
            return;
        }
        $insert = $this->pdo()->prepare(
            'INSERT INTO sales_employee_managers (employee_id, manager_designation, manager_employee_id) VALUES (?, ?, ?)'
        );
        foreach ($managers as $designation => $managerId) {
            $insert->execute([$employeeId, $designation, $managerId]);
        }
    }

    private function clearInboundMismatches(int $managerId, ?string $designation): void
    {
        if ($designation === null) {
            $this->pdo()->prepare('DELETE FROM sales_employee_managers WHERE manager_employee_id = ?')->execute([$managerId]);

            return;
        }
        $this->pdo()->prepare(
            'DELETE FROM sales_employee_managers WHERE manager_employee_id = ? AND manager_designation <> ?'
        )->execute([$managerId, $designation]);
    }

    private function count(string $sql, array $params): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
