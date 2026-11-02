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
    public function stockists(string $like): array
    {
        $escape = self::ESCAPE;

        return $this->many(
            "SELECT s.id, s.name, s.code, h.name AS headquarters_name
             FROM stockists s
             JOIN headquarters h ON h.id = s.headquarters_id
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

    /** @return list<array<string, mixed>> */
    public function products(string $like): array
    {
        $escape = self::ESCAPE;

        return $this->many(
            "SELECT id, name, code, deleted_at FROM products
             WHERE name LIKE ? {$escape} OR code LIKE ? {$escape}
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

    /** @return list<array<string, mixed>> */
    public function sales(string $like): array
    {
        $escape = self::ESCAPE;

        return $this->many(
            "SELECT s.id, DATE_FORMAT(s.sales_month, '%Y-%m') AS sales_month,
                    st.name AS stockist_name, h.name AS headquarters_name
             FROM sales_entries s
             JOIN stockists st ON st.id = s.stockist_id
             JOIN headquarters h ON h.id = s.headquarters_id
             WHERE st.name LIKE ? {$escape} OR st.code LIKE ? {$escape}
                OR h.name LIKE ? {$escape} OR DATE_FORMAT(s.sales_month, '%Y-%m') LIKE ? {$escape}
             ORDER BY s.sales_month, st.name, s.id
             LIMIT 6",
            [$like, $like, $like, $like]
        );
    }
}
