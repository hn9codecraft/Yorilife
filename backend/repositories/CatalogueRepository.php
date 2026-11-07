<?php

declare(strict_types=1);

namespace App\Repositories;

/** Group categories, categories, tags and badges. */
final class CatalogueRepository extends Repository
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->many(
            'SELECT id, kind, parent_id, name, description, status, deleted_at, created_at, updated_at
             FROM catalogue_terms ORDER BY name, id'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one(
            'SELECT id, kind, parent_id, name, description, status, deleted_at, created_at, updated_at
             FROM catalogue_terms WHERE id = ?',
            [$id]
        );
    }

    /** @param array{kind: string, name: string} $fields */
    public function insert(array $fields): int
    {
        return (int) $this->run(
            fn (): int => $this->insertRow('catalogue_terms', $fields),
            ['catalogue_terms_kind_name_unique' => ['name' => 'A record with this name already exists for this type, including deleted records. Restore it instead of creating another.']]
        );
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $this->run(
            fn () => $this->updateRow('catalogue_terms', $id, $fields),
            ['catalogue_terms_kind_name_unique' => ['name' => 'A record with this name already exists for this type, including deleted records.']]
        );
    }

    /** Active product categories that still belong to this group. */
    public function activeChildCategories(int $id): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM catalogue_terms WHERE parent_id = ? AND deleted_at IS NULL');
        $statement->execute([$id]);

        return (int) $statement->fetchColumn();
    }

    /** Clear group links on child categories (used when a group is deleted). */
    public function clearChildParents(int $groupId): void
    {
        $this->pdo()->prepare('UPDATE catalogue_terms SET parent_id = NULL WHERE parent_id = ?')->execute([$groupId]);
    }

    /** Clear product.category_id for this category (products stay). */
    public function clearProductCategories(int $categoryId): void
    {
        $this->pdo()->prepare('UPDATE products SET category_id = NULL WHERE category_id = ?')->execute([$categoryId]);
    }

    /** Remove tag/badge links from products without deleting the products. */
    public function clearProductTermLinks(int $termId): void
    {
        $this->pdo()->prepare('DELETE FROM product_terms WHERE term_id = ?')->execute([$termId]);
    }

    public function setDeleted(int $id, bool $deleted): void
    {
        $this->pdo()->prepare('UPDATE catalogue_terms SET deleted_at = ' . ($deleted ? 'UTC_TIMESTAMP()' : 'NULL') . ' WHERE id = ?')->execute([$id]);
    }

    /** Active products that still point at this term. */
    public function activeReferences(int $id): int
    {
        $category = $this->pdo()->prepare('SELECT COUNT(*) FROM products WHERE category_id = ? AND deleted_at IS NULL');
        $category->execute([$id]);
        $linked = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM product_terms pt JOIN products p ON p.id = pt.product_id WHERE pt.term_id = ? AND p.deleted_at IS NULL'
        );
        $linked->execute([$id]);

        return (int) $category->fetchColumn() + (int) $linked->fetchColumn();
    }
}
