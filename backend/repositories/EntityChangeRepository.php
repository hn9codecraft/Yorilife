<?php

declare(strict_types=1);

namespace App\Repositories;

final class EntityChangeRepository extends Repository
{
    /**
     * @param array{
     *   from?: ?string,
     *   to?: ?string,
     *   changedBy?: ?int,
     *   entityType?: ?string,
     *   entityTypes?: list<string>,
     *   entityId?: ?int,
     *   field?: ?string,
     *   q?: ?string,
     *   limit?: int,
     *   offset?: int
     * } $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $limit = max(1, min(5000, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $sql = 'SELECT c.id, c.entity_type, c.entity_id, c.entity_label, c.field, c.old_value, c.new_value,
                       c.changed_by, u.name AS changed_by_name, c.changed_at, c.source, c.request_id
                FROM entity_changes c
                LEFT JOIN users u ON u.id = c.changed_by'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY c.changed_at DESC, c.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        return $this->many($sql, $params);
    }

    /** @param array<string, mixed> $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters, 'c.');
        $sql = 'SELECT COUNT(*) AS total FROM entity_changes c'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
        $row = $this->one($sql, $params);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function buildWhere(array $filters, string $prefix = 'c.'): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['from'])) {
            $where[] = $prefix . 'changed_at >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = $prefix . 'changed_at <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['changedBy'])) {
            $where[] = $prefix . 'changed_by = ?';
            $params[] = (int) $filters['changedBy'];
        }
        if (!empty($filters['entityType'])) {
            $where[] = $prefix . 'entity_type = ?';
            $params[] = $filters['entityType'];
        }
        if (!empty($filters['entityTypes']) && is_array($filters['entityTypes'])) {
            $types = array_values(array_filter($filters['entityTypes'], static fn ($t) => is_string($t) && $t !== ''));
            if ($types !== []) {
                $marks = implode(', ', array_fill(0, count($types), '?'));
                $where[] = $prefix . "entity_type IN ({$marks})";
                foreach ($types as $type) {
                    $params[] = $type;
                }
            }
        }
        if (!empty($filters['entityId'])) {
            $where[] = $prefix . 'entity_id = ?';
            $params[] = (int) $filters['entityId'];
        }
        if (!empty($filters['field'])) {
            $where[] = $prefix . 'field = ?';
            $params[] = $filters['field'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(' . $prefix . 'entity_label LIKE ? OR ' . $prefix . 'field LIKE ? OR ' . $prefix . 'old_value LIKE ? OR ' . $prefix . 'new_value LIKE ?)';
            $like = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $archived = $filters['archived'] ?? 'active';
        if ($archived === 'only') {
            $where[] = $prefix . 'archived_at IS NOT NULL';
        } elseif ($archived !== 'all') {
            $where[] = $prefix . 'archived_at IS NULL';
        }

        return [$where, $params];
    }
}
