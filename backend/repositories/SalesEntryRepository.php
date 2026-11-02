<?php

declare(strict_types=1);

namespace App\Repositories;

final class SalesEntryRepository extends Repository
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->many(
            'SELECT id, sales_month, headquarters_id, stockist_id, achievement_value, bonus, deduction,
                    final_value, created_at, updated_at
             FROM sales_entries
             ORDER BY sales_month, id'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one(
            'SELECT id, sales_month, headquarters_id, stockist_id, achievement_value, bonus, deduction, final_value, created_at, updated_at
             FROM sales_entries WHERE id = ?',
            [$id]
        );
    }

    /** @param array<string, mixed> $fields */
    public function insert(array $fields): int
    {
        return (int) $this->run(
            fn (): int => $this->insertRow('sales_entries', $fields),
            ['sales_entries_stockist_month_unique' => ['stockistId' => 'This stockist already has a sales entry for that month.']]
        );
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $this->run(
            fn () => $this->updateRow('sales_entries', $id, $fields),
            ['sales_entries_stockist_month_unique' => ['stockistId' => 'This stockist already has a sales entry for that month.']]
        );
    }
}
