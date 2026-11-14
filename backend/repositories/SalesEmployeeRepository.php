<?php

declare(strict_types=1);

namespace App\Repositories;

/** Sales Office employees, the states and headquarters they cover, and their managers. */
final class SalesEmployeeRepository extends Repository
{
    private const COLUMNS = 'e.id, e.first_name, e.last_name, e.name, e.employee_code, e.phone, e.email, e.designation,
        e.created_at, e.updated_at';

    /** @var array<string, string> */
    private const LINK_TABLES = [
        'sales_employee_states' => 'location_id',
        'sales_employee_headquarters' => 'location_headquarters_id',
    ];

    /** @var array<string, array<string, string>> */
    private const DUPLICATE_KEYS = [
        'active_employee_code' => ['employeeCode' => 'This employee code is already used.'],
        'active_phone' => ['phone' => 'This phone number is already used.'],
        'active_email' => ['email' => 'This email is already used.'],
    ];

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->many(
            'SELECT ' . self::COLUMNS . ' FROM sales_employees e
             WHERE e.deleted_at IS NULL
             ORDER BY (e.first_name IS NULL AND e.last_name IS NULL), e.first_name, e.last_name, e.id'
        );

        return $this->withRelations($rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->one(
            'SELECT ' . self::COLUMNS . ' FROM sales_employees e
             WHERE e.id = ? AND e.deleted_at IS NULL',
            [$id]
        );

        if ($row === null) {
            return null;
        }

        return $this->withRelations([$row])[0];
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
        return $this->count(
            'SELECT COUNT(*) FROM sales_employee_states ses
             JOIN sales_employees e ON e.id = ses.employee_id AND e.deleted_at IS NULL
             WHERE ses.location_id = ?',
            [$locationId]
        );
    }

    public function countUsingHeadquarters(int $headquartersId): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM sales_employee_headquarters seh
             JOIN sales_employees e ON e.id = seh.employee_id AND e.deleted_at IS NULL
             WHERE seh.location_headquarters_id = ?',
            [$headquartersId]
        );
    }

    public function codeTaken(string $code, ?int $exceptId): bool
    {
        return $this->valueTaken(
            'employee_code COLLATE utf8mb4_bin = CAST(? AS CHAR(100) CHARACTER SET utf8mb4) COLLATE utf8mb4_bin',
            $code,
            $exceptId,
        );
    }

    public function phoneTaken(string $phone, ?int $exceptId): bool
    {
        return $this->valueTaken('TRIM(phone) = ?', $phone, $exceptId);
    }

    public function emailTaken(string $email, ?int $exceptId): bool
    {
        return $this->valueTaken('LOWER(TRIM(email)) = ?', $email, $exceptId);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, int> $managers designation => manager employee id
     * @param list<int> $locationIds
     * @param list<int> $headquartersIds
     */
    public function insert(array $fields, array $managers, array $locationIds, array $headquartersIds): int
    {
        return $this->run(function () use ($fields, $managers, $locationIds, $headquartersIds): int {
            return $this->transaction(function () use ($fields, $managers, $locationIds, $headquartersIds): int {
                $id = $this->insertRow('sales_employees', $fields);
                $this->replaceManagers($id, $managers);
                $this->replaceLinks($id, 'sales_employee_states', $locationIds);
                $this->replaceLinks($id, 'sales_employee_headquarters', $headquartersIds);

                return $id;
            });
        }, self::DUPLICATE_KEYS);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, int> $managers
     * @param list<int> $locationIds
     * @param list<int> $headquartersIds
     */
    public function update(int $id, array $fields, array $managers, ?string $designation, array $locationIds, array $headquartersIds): void
    {
        $this->run(function () use ($id, $fields, $managers, $designation, $locationIds, $headquartersIds): void {
            $this->transaction(function () use ($id, $fields, $managers, $designation, $locationIds, $headquartersIds): void {
                $this->updateRow('sales_employees', $id, $fields);
                $this->replaceManagers($id, $managers);
                $this->replaceLinks($id, 'sales_employee_states', $locationIds);
                $this->replaceLinks($id, 'sales_employee_headquarters', $headquartersIds);
                $this->clearInboundMismatches($id, $designation);
            });
        }, self::DUPLICATE_KEYS);
    }

    public function softDelete(int $id, string $at): void
    {
        $this->transaction(function () use ($id, $at): void {
            $this->pdo()->prepare('DELETE FROM sales_employee_managers WHERE employee_id = ? OR manager_employee_id = ?')->execute([$id, $id]);
            $this->pdo()->prepare('DELETE FROM sales_employee_states WHERE employee_id = ?')->execute([$id]);
            $this->pdo()->prepare('DELETE FROM sales_employee_headquarters WHERE employee_id = ?')->execute([$id]);
            $this->pdo()->prepare(
                'UPDATE sales_employees
                 SET deleted_at = ?, location_id = NULL, location_headquarters_id = NULL, updated_at = ?
                 WHERE id = ?'
            )->execute([$at, $at, $id]);
        });
    }

    private function valueTaken(string $comparison, string $value, ?int $exceptId): bool
    {
        $sql = "SELECT 1 FROM sales_employees WHERE deleted_at IS NULL AND {$comparison}";
        $params = [$value];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return $this->exists($sql, $params);
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function withRelations(array $rows): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $managers = $this->managerLinks($ids);
        $states = $this->stateLinks($ids);
        $headquarters = $this->headquartersLinks($ids);
        foreach ($rows as $index => $row) {
            $id = (int) $row['id'];
            $rows[$index]['managers'] = $managers[$id] ?? [];
            $rows[$index]['states'] = $states[$id] ?? [];
            $rows[$index]['headquarters'] = $headquarters[$id] ?? [];
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
                    m.first_name AS manager_first_name, m.last_name AS manager_last_name,
                    m.name AS manager_name, m.employee_code AS manager_code
             FROM sales_employee_managers sem
             JOIN sales_employees m ON m.id = sem.manager_employee_id AND m.deleted_at IS NULL
             WHERE sem.employee_id IN ({$marks})
               AND m.designation = sem.manager_designation",
            $ids
        );

        return $this->groupByEmployee($links);
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function stateLinks(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $links = $this->many(
            "SELECT ses.employee_id, ses.location_id, l.name AS location_name
             FROM sales_employee_states ses
             JOIN locations l ON l.id = ses.location_id
             WHERE ses.employee_id IN ({$marks})
             ORDER BY l.name, l.id",
            $ids
        );

        return $this->groupByEmployee($links);
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function headquartersLinks(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $links = $this->many(
            "SELECT seh.employee_id, seh.location_headquarters_id, h.name AS headquarters_name,
                    h.code AS headquarters_code, h.location_id, l.name AS location_name
             FROM sales_employee_headquarters seh
             JOIN location_headquarters h ON h.id = seh.location_headquarters_id
             JOIN locations l ON l.id = h.location_id
             WHERE seh.employee_id IN ({$marks})
             ORDER BY l.name, h.name, h.id",
            $ids
        );

        return $this->groupByEmployee($links);
    }

    /**
     * @param list<array<string, mixed>> $links
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupByEmployee(array $links): array
    {
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

    /** @param list<int> $ids */
    private function replaceLinks(int $employeeId, string $table, array $ids): void
    {
        $column = self::LINK_TABLES[$table] ?? throw new \InvalidArgumentException('Unknown link table.');
        $this->pdo()->prepare("DELETE FROM {$table} WHERE employee_id = ?")->execute([$employeeId]);
        if ($ids === []) {
            return;
        }
        $insert = $this->pdo()->prepare("INSERT INTO {$table} (employee_id, {$column}) VALUES (?, ?)");
        foreach ($ids as $id) {
            $insert->execute([$employeeId, $id]);
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
