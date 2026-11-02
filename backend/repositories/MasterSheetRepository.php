<?php

declare(strict_types=1);

namespace App\Repositories;

final class MasterSheetRepository extends Repository
{
    /** @return list<array<string, mixed>> */
    public function years(): array
    {
        return $this->many('SELECT id, label, starts_on, ends_on, created_by, created_at FROM financial_years ORDER BY label');
    }

    /** @return array<string, mixed>|null */
    public function findYear(string $label): ?array
    {
        return $this->one('SELECT id, label, starts_on, ends_on, created_by, created_at FROM financial_years WHERE label = ?', [$label]);
    }

    /** @param array{label: string, starts_on: string, ends_on: string, created_by: int} $fields */
    public function insertYear(array $fields): int
    {
        return (int) $this->run(
            fn (): int => $this->insertRow('financial_years', $fields),
            ['financial_years_label_unique' => ['label' => 'That financial year already exists.']]
        );
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        return $this->many(
            'SELECT e.year_id, y.label AS year_label, e.product_id, e.inclusion, e.strip_pack, e.mrp, e.ptr, e.pts, e.updated_at
             FROM master_sheet_entries e
             JOIN financial_years y ON y.id = e.year_id'
        );
    }

    /** @return array<string, mixed>|null */
    public function findEntry(int $yearId, int $productId): ?array
    {
        return $this->one(
            'SELECT id, inclusion, strip_pack, mrp, ptr, pts, scheme FROM master_sheet_entries WHERE year_id = ? AND product_id = ?',
            [$yearId, $productId]
        );
    }

    /** @return list<int> */
    public function productIdsForYear(int $yearId): array
    {
        $rows = $this->many('SELECT product_id FROM master_sheet_entries WHERE year_id = ?', [$yearId]);

        return array_map(static fn (array $row): int => (int) $row['product_id'], $rows);
    }

    /** @param array<string, mixed> $fields */
    public function insertEntry(array $fields): void
    {
        $this->insertRow('master_sheet_entries', $fields);
    }

    /** @param array<string, mixed> $fields */
    public function updateEntry(int $id, array $fields): void
    {
        $this->updateRow('master_sheet_entries', $id, $fields);
    }

    /** @param list<array<string, mixed>> $rows */
    public function addChanges(array $rows): void
    {
        foreach ($rows as $row) {
            $this->insertRow('master_sheet_changes', $row);
        }
    }

    /** @return list<array<string, mixed>> */
    public function changes(): array
    {
        return $this->many(
            'SELECT c.id, y.label AS year_label, c.product_id, c.product_name, c.field,
                    c.old_value, c.new_value, c.changed_at, u.name AS changed_by_name
             FROM master_sheet_changes c
             JOIN financial_years y ON y.id = c.year_id
             LEFT JOIN users u ON u.id = c.changed_by
             ORDER BY c.changed_at, c.id'
        );
    }
}
