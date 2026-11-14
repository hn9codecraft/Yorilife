<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Bonus Calc rows (who is on a financial year) and month lines
 * (quantities and the rates copied at save time).
 */
final class BonusCalcRepository extends Repository
{
    private const EMPLOYEE_NAME = "COALESCE(
        NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(e.first_name), ''), NULLIF(TRIM(e.last_name), ''))), ''),
        NULLIF(TRIM(e.name), '')
    )";

    /** @return list<array<string, mixed>> */
    public function rowsForYear(string $year): array
    {
        return $this->many(
            'SELECT r.id, r.financial_year, r.sales_employee_id, r.deleted_at, e.employee_code, e.deleted_at AS employee_deleted_at, '
            . self::EMPLOYEE_NAME . ' AS employee_name
             FROM bonus_calc_rows r
             JOIN sales_employees e ON e.id = r.sales_employee_id
             WHERE r.deleted_at IS NULL AND r.financial_year = ?
             ORDER BY employee_name, e.employee_code, r.id',
            [$year]
        );
    }

    /** @return array<string, mixed>|null */
    public function findRow(int $id): ?array
    {
        return $this->one(
            'SELECT r.id, r.financial_year, r.sales_employee_id, r.deleted_at, e.employee_code, '
            . self::EMPLOYEE_NAME . ' AS employee_name
             FROM bonus_calc_rows r
             JOIN sales_employees e ON e.id = r.sales_employee_id
             WHERE r.id = ?',
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function activeRowForEmployee(string $year, int $employeeId): ?array
    {
        return $this->one(
            'SELECT id, financial_year, sales_employee_id, deleted_at
             FROM bonus_calc_rows
             WHERE deleted_at IS NULL AND financial_year = ? AND sales_employee_id = ?',
            [$year, $employeeId]
        );
    }

    public function insertRowFor(string $year, int $employeeId): int
    {
        return (int) $this->run(
            fn (): int => $this->insertRow('bonus_calc_rows', [
                'financial_year' => $year,
                'sales_employee_id' => $employeeId,
            ]),
            ['bonus_calc_rows_active_employee_year_unique' => ['employeeIds' => 'That employee is already in this financial year.']]
        );
    }

    public function reassign(int $id, int $employeeId): void
    {
        $this->run(
            fn () => $this->pdo()->prepare(
                'UPDATE bonus_calc_rows SET sales_employee_id = ? WHERE id = ? AND deleted_at IS NULL'
            )->execute([$employeeId, $id]),
            ['bonus_calc_rows_active_employee_year_unique' => ['employeeId' => 'That employee is already in this financial year.']]
        );
    }

    public function moveLines(string $year, int $fromEmployeeId, int $toEmployeeId): void
    {
        $this->pdo()->prepare(
            'UPDATE bonus_calc_lines SET sales_employee_id = ? WHERE financial_year = ? AND sales_employee_id = ?'
        )->execute([$toEmployeeId, $year, $fromEmployeeId]);
    }

    public function employeeHasLines(string $year, int $employeeId): bool
    {
        return $this->exists(
            'SELECT 1 FROM bonus_calc_lines WHERE financial_year = ? AND sales_employee_id = ? LIMIT 1',
            [$year, $employeeId]
        );
    }

    public function softDelete(int $id): void
    {
        $this->pdo()->prepare(
            'UPDATE bonus_calc_rows SET deleted_at = UTC_TIMESTAMP() WHERE id = ? AND deleted_at IS NULL'
        )->execute([$id]);
    }

    /**
     * Month bonus totals for the employees on one financial year.
     *
     * @param list<int> $employeeIds
     * @return array<int, array<int, array{total: string, count: int}>>
     */
    public function monthTotals(string $year, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($employeeIds), '?'));
        $rows = $this->many(
            "SELECT sales_employee_id, month, SUM(bonus_amount) AS total, COUNT(*) AS line_count
             FROM bonus_calc_lines
             WHERE financial_year = ? AND sales_employee_id IN ({$marks})
             GROUP BY sales_employee_id, month",
            [$year, ...$employeeIds]
        );
        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['sales_employee_id']][(int) $row['month']] = [
                'total' => (string) $row['total'],
                'count' => (int) $row['line_count'],
            ];
        }

        return $totals;
    }

    /** Active Sales Office employees for the picker. @return list<array<string, mixed>> */
    public function activeEmployees(): array
    {
        return $this->many(
            'SELECT e.id, e.employee_code, ' . self::EMPLOYEE_NAME . ' AS employee_name
             FROM sales_employees e
             WHERE e.deleted_at IS NULL
             ORDER BY employee_name, e.employee_code, e.id'
        );
    }

    /** @return array<string, mixed>|null */
    public function findEmployee(int $id): ?array
    {
        return $this->one(
            'SELECT e.id, e.employee_code, e.deleted_at, ' . self::EMPLOYEE_NAME . ' AS employee_name
             FROM sales_employees e WHERE e.id = ?',
            [$id]
        );
    }

    /** @return list<array<string, mixed>> */
    public function stockistsForEmployee(int $employeeId): array
    {
        return $this->many(
            'SELECT id, name, code, assigned_employee_id
             FROM stockists
             WHERE assigned_employee_id = ?
             ORDER BY name, code, id',
            [$employeeId]
        );
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    public function stockistsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->many(
            "SELECT id, name, code, assigned_employee_id FROM stockists WHERE id IN ({$marks})",
            $ids
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        return $byId;
    }

    /** @return list<array<string, mixed>> */
    public function activeProducts(): array
    {
        return $this->many(
            'SELECT id, name, packaging_unit, mrp, ptr, pts, scheme, net_rate, deleted_at
             FROM products
             WHERE deleted_at IS NULL
             ORDER BY name, id'
        );
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    public function productsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->many(
            "SELECT id, name, packaging_unit, mrp, ptr, pts, scheme, net_rate, deleted_at
             FROM products WHERE id IN ({$marks})",
            $ids
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        return $byId;
    }

    /** @return list<array<string, mixed>> */
    public function linesForMonth(string $year, int $employeeId, int $month): array
    {
        return $this->many(
            'SELECT id, financial_year, sales_employee_id, month, stockist_id, product_id,
                    product_name, packaging_unit, mrp, ptr, pts, scheme, net_rate,
                    opening_qty, new_purchase_qty, product_sales_qty, bonus_amount
             FROM bonus_calc_lines
             WHERE financial_year = ? AND sales_employee_id = ? AND month = ?',
            [$year, $employeeId, $month]
        );
    }

    /** @param array<string, mixed> $fields */
    public function insertLine(array $fields): int
    {
        return $this->insertRow('bonus_calc_lines', $fields);
    }

    /** @param array<string, mixed> $fields */
    public function updateLineQuantities(int $id, array $fields): void
    {
        $this->updateRow('bonus_calc_lines', $id, $fields);
    }

    public function deleteLine(int $id): void
    {
        $this->pdo()->prepare('DELETE FROM bonus_calc_lines WHERE id = ?')->execute([$id]);
    }

    public function stockistLineCount(int $stockistId): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM bonus_calc_lines WHERE stockist_id = ?');
        $statement->execute([$stockistId]);

        return (int) $statement->fetchColumn();
    }
}
