<?php

declare(strict_types=1);

namespace App\Repositories;

final class ProductRepository extends Repository
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->many('SELECT id, name, code, category_id, scheme, scheme_buy_qty, scheme_free_qty, deleted_at, created_at, updated_at FROM products ORDER BY name, id');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT id, name, code, category_id, scheme, scheme_buy_qty, scheme_free_qty, deleted_at, created_at, updated_at FROM products WHERE id = ?', [$id]);
    }

    /** @return array<int, array{tag: list<int>, badge: list<int>}> */
    public function linksByProduct(): array
    {
        $rows = $this->many(
            'SELECT pt.product_id, pt.term_id, t.kind
             FROM product_terms pt
             JOIN catalogue_terms t ON t.id = pt.term_id'
        );
        $grouped = [];
        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $kind = $row['kind'] === 'tag' ? 'tag' : 'badge';
            $grouped[$productId][$kind][] = (int) $row['term_id'];
        }

        return $grouped;
    }

    /** @return list<array<string, mixed>> */
    public function changes(): array
    {
        return $this->many(
            'SELECT c.id, c.product_id, c.product_name, c.action, c.changes, c.changed_at, u.name AS changed_by_name
             FROM product_changes c
             LEFT JOIN users u ON u.id = c.changed_by
             ORDER BY c.changed_at, c.id'
        );
    }

    /** @param array<string, mixed> $fields */
    public function insert(array $fields): int
    {
        return (int) $this->run(
            fn (): int => $this->insertRow('products', $fields),
            ['products_active_code_unique' => ['code' => 'An active product already uses this code.']]
        );
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $this->run(
            fn () => $this->updateRow('products', $id, $fields),
            ['products_active_code_unique' => ['code' => 'An active product already uses this code.']]
        );
    }

    public function setDeleted(int $id, bool $deleted): void
    {
        $this->run(
            fn () => $this->pdo()->prepare('UPDATE products SET deleted_at = ' . ($deleted ? 'UTC_TIMESTAMP()' : 'NULL') . ' WHERE id = ?')->execute([$id]),
            ['products_active_code_unique' => ['code' => 'An active product already uses this code. Change that code before restoring this product.']]
        );
    }

    /** @param list<int> $termIds */
    public function replaceTerms(int $productId, array $termIds): void
    {
        $this->pdo()->prepare('DELETE FROM product_terms WHERE product_id = ?')->execute([$productId]);
        $insert = $this->pdo()->prepare('INSERT INTO product_terms (product_id, term_id) VALUES (?, ?)');
        foreach ($termIds as $termId) {
            $insert->execute([$productId, $termId]);
        }
    }

    /** @param list<array{field: string, from: ?string, to: ?string}> $changes */
    public function addChange(int $productId, string $productName, string $action, array $changes, int $userId): void
    {
        $this->insertRow('product_changes', [
            'product_id' => $productId,
            'product_name' => $productName,
            'action' => $action,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'changed_by' => $userId,
        ]);
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    public function termsById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->many("SELECT id, kind, parent_id, name, deleted_at FROM catalogue_terms WHERE id IN ({$marks})", $ids);
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['id']] = $row;
        }

        return $indexed;
    }
}
