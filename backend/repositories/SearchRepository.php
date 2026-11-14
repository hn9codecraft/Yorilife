<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Bound LIKE lookups for global search. The pattern is always a parameter.
 * LIMIT is a literal because native prepares cannot bind it.
 */
final class SearchRepository extends Repository
{
    private const ESCAPE = "ESCAPE '\\\\'";

    /** @return list<array<string, mixed>> */
    public function headquarters(string $like): array
    {
        $escape = self::ESCAPE;

        return $this->many(
            "SELECT id, name, code, state FROM headquarters
             WHERE name LIKE ? {$escape} OR code LIKE ? {$escape} OR state LIKE ? {$escape}
             ORDER BY name, id
             LIMIT 6",
            [$like, $like, $like]
        );
    }

    /** @return list<array<string, mixed>> */
    public function managers(string $like): array
    {
        $escape = self::ESCAPE;

        return $this->many(
            "SELECT id, level, name, code, email FROM managers
             WHERE name LIKE ? {$escape} OR code LIKE ? {$escape} OR email LIKE ? {$escape}
             ORDER BY name, id
             LIMIT 6",
            [$like, $like, $like]
        );
    }

    /** @return list<array<string, mixed>> */
    public function salesEmployees(string $like): array
    {
        $escape = self::ESCAPE;

        return $this->many(
            "SELECT id, first_name, last_name, name, employee_code, designation FROM sales_employees
             WHERE deleted_at IS NULL
               AND (first_name LIKE ? {$escape} OR last_name LIKE ? {$escape} OR name LIKE ? {$escape}
                    OR employee_code LIKE ? {$escape} OR email LIKE ? {$escape} OR phone LIKE ? {$escape})
             ORDER BY (first_name IS NULL AND last_name IS NULL), first_name, last_name, id
             LIMIT 6",
            [$like, $like, $like, $like, $like, $like]
        );
    }

    /** @return list<array<string, mixed>> */
    public function stockists(string $like): array
    {
        $escape = self::ESCAPE;

        return $this->many(
            "SELECT s.id, s.name, s.code, COALESCE(lh.name, h.name) AS headquarters_name
             FROM stockists s
             LEFT JOIN location_headquarters lh ON lh.id = s.location_headquarters_id
             LEFT JOIN headquarters h ON h.id = s.headquarters_id
             WHERE s.name LIKE ? {$escape} OR s.code LIKE ? {$escape}
             ORDER BY s.name, s.id
             LIMIT 6",
            [$like, $like]
        );
    }

    /** @return list<array<string, mixed>> */
    public function locations(string $like): array
    {
        $escape = self::ESCAPE;

        return $this->many(
            "SELECT id, name, code FROM locations
             WHERE name LIKE ? {$escape} OR code LIKE ? {$escape}
             ORDER BY name, id
             LIMIT 6",
            [$like, $like]
        );
    }

    /**
     * @param list<string> $kinds catalogue kind values stored in the database
     * @return list<array<string, mixed>>
     */
    public function terms(string $like, array $kinds): array
    {
        $marks = implode(', ', array_fill(0, count($kinds), '?'));
        $escape = self::ESCAPE;

        return $this->many(
            "SELECT id, kind, name, deleted_at FROM catalogue_terms
             WHERE kind IN ({$marks}) AND name LIKE ? {$escape}
             ORDER BY name, id
             LIMIT 6",
            [...$kinds, $like]
        );
    }

    /** Product types ('type') or product groups ('group') that are not deleted. @return list<array<string, mixed>> */
    public function productCategories(string $like, string $kind): array
    {
        $table = $kind === 'type' ? 'product_types' : 'product_groups';
        $escape = self::ESCAPE;

        return $this->many(
            "SELECT id, name FROM {$table}
             WHERE deleted_at IS NULL AND name LIKE ? {$escape}
             ORDER BY name, id
             LIMIT 6",
            [$like]
        );
    }

    /** @return list<array<string, mixed>> */
    public function products(string $like): array
    {
        $escape = self::ESCAPE;

        return $this->many(
            "SELECT id, name, code, deleted_at FROM products
             WHERE deleted_at IS NULL AND (name LIKE ? {$escape} OR code LIKE ? {$escape})
             ORDER BY name, id
             LIMIT 6",
            [$like, $like]
        );
    }

    /** @return list<array<string, mixed>> */
    public function years(string $like): array
    {
        $escape = self::ESCAPE;

        return $this->many(
            "SELECT id, label FROM financial_years
             WHERE label LIKE ? {$escape}
             ORDER BY label, id
             LIMIT 6",
            [$like]
        );
    }

}
