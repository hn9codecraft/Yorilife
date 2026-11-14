<?php

declare(strict_types=1);

namespace App\Repositories;

use InvalidArgumentException;

/**
 * Product Type and Product Group masters (the Category page).
 * Both tables have the same shape: id, name, deleted_at, timestamps.
 */
final class ProductCategoryRepository extends Repository
{
    /** kind => [table, products column] */
    private const KINDS = [
        'type' => ['product_types', 'product_type_id'],
        'group' => ['product_groups', 'product_group_id'],
    ];

    public function __construct(private readonly string $kind)
    {
        if (!isset(self::KINDS[$kind])) {
            throw new InvalidArgumentException("Unknown product category kind: {$kind}");
        }
    }

    private function table(): string
    {
        return self::KINDS[$this->kind][0];
    }

    private function productColumn(): string
    {
        return self::KINDS[$this->kind][1];
    }

    /** Records that are not deleted, by name. @return list<array<string, mixed>> */
    public function active(): array
    {
        return $this->many(
            'SELECT id, name, deleted_at, created_at, updated_at FROM ' . $this->table() . ' WHERE deleted_at IS NULL ORDER BY name, id'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT id, name, deleted_at, created_at, updated_at FROM ' . $this->table() . ' WHERE id = ?', [$id]);
    }

    public function insert(string $name): int
    {
        return (int) $this->run(
            fn (): int => $this->insertRow($this->table(), ['name' => $name]),
            [$this->table() . '_active_name_unique' => ['name' => $this->duplicateMessage()]]
        );
    }

    public function rename(int $id, string $name): void
    {
        $this->run(
            fn () => $this->updateRow($this->table(), $id, ['name' => $name]),
            [$this->table() . '_active_name_unique' => ['name' => $this->duplicateMessage()]]
        );
    }

    public function markDeleted(int $id): void
    {
        $this->pdo()->prepare('UPDATE ' . $this->table() . ' SET deleted_at = UTC_TIMESTAMP() WHERE id = ? AND deleted_at IS NULL')->execute([$id]);
    }

    /** Products (not deleted) that use this record. */
    public function activeProductCount(int $id): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM products WHERE ' . $this->productColumn() . ' = ? AND deleted_at IS NULL');
        $statement->execute([$id]);

        return (int) $statement->fetchColumn();
    }

    /** Number of active products per record id. @return array<int, int> */
    public function productCounts(): array
    {
        $column = $this->productColumn();
        $rows = $this->many("SELECT {$column} AS id, COUNT(*) AS total FROM products WHERE {$column} IS NOT NULL AND deleted_at IS NULL GROUP BY {$column}");
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }

    private function duplicateMessage(): string
    {
        return $this->kind === 'type' ? 'This product type already exists.' : 'This product group already exists.';
    }
}