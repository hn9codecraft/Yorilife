<?php

declare(strict_types=1);

namespace App\Repositories;

final class AuditLogRepository extends Repository
{
    /**
     * @param array{
     *   from?: ?string,
     *   to?: ?string,
     *   actorId?: ?int,
     *   module?: ?string,
     *   action?: ?string,
     *   result?: ?string,
     *   q?: ?string,
     *   limit?: int,
     *   offset?: int
     * } $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters): array
    {
        [$sql, $params] = $this->union($filters);
        $limit = max(1, min(5000, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        return $this->many(
            $sql . ' ORDER BY occurred_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
    }

    /** @param array<string, mixed> $filters */
    public function count(array $filters): int
    {
        [$sql, $params] = $this->union($filters);
        $row = $this->one('SELECT COUNT(*) AS total FROM (' . $sql . ') AS combined', $params);

        return (int) ($row['total'] ?? 0);
    }

    /** @return array{archived: int} */
    public function archiveOlderThan(string $archivedAt, string $cutoff): int
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE audit_logs SET archived_at = ? WHERE archived_at IS NULL AND occurred_at < ?'
        );
        $stmt->execute([$archivedAt, $cutoff]);

        return $stmt->rowCount();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one(
            'SELECT id, occurred_at, actor_id, actor_label, module, action, result,
                    target_type, target_id, target_label, ip_address, user_agent, request_id, details
             FROM audit_logs WHERE id = ?',
            [$id]
        );
    }

    /**
     * Import audit rows bridged into the same shape (for System → Audit Logs).
     * @return list<array<string, mixed>>
     */
    public function bridgedImports(?string $fromUtc, ?string $toUtc, int $limit = 200): array
    {
        $where = [];
        $params = [];
        if ($fromUtc !== null) {
            $where[] = 'a.created_at >= ?';
            $params[] = $fromUtc;
        }
        if ($toUtc !== null) {
            $where[] = 'a.created_at <= ?';
            $params[] = $toUtc;
        }
        $sql = "SELECT CONCAT('import-', a.id) AS bridge_id, a.created_at AS occurred_at, a.user_id AS actor_id,
                       u.name AS actor_label, 'import' AS module, a.action, 'ok' AS result,
                       'import' AS target_type, a.import_id AS target_id,
                       CONCAT('Import #', a.import_id) AS target_label, a.ip_address,
                       NULL AS user_agent, NULL AS request_id, a.details
                FROM import_audit_logs a
                LEFT JOIN users u ON u.id = a.user_id"
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . max(1, min(500, $limit));

        return $this->many($sql, $params);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['from'])) {
            $where[] = 'occurred_at >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'occurred_at <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['actorId'])) {
            $where[] = 'actor_id = ?';
            $params[] = (int) $filters['actorId'];
        }
        if (!empty($filters['module'])) {
            $where[] = 'module = ?';
            $params[] = $filters['module'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['result'])) {
            $where[] = 'result = ?';
            $params[] = $filters['result'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(actor_label LIKE ? OR target_label LIKE ? OR action LIKE ? OR module LIKE ? OR request_id LIKE ?)';
            $like = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $archived = $filters['archived'] ?? 'active';
        if ($archived === 'only') {
            $where[] = 'archived_at IS NOT NULL';
        } elseif ($archived !== 'all') {
            $where[] = 'archived_at IS NULL';
        }

        return [$where, $params];
    }

    /**
     * audit_logs, plus import_audit_logs when that module is in scope.
     * Pagination happens once, in SQL.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function union(array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $audit = 'SELECT a.id, a.occurred_at, a.actor_id, a.actor_label, a.module, a.action, a.result,
                         a.target_type, a.target_id, a.target_label, a.ip_address, a.user_agent, a.request_id,
                         a.details, a.archived_at, 0 AS bridged
                  FROM audit_logs a'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

        if (!$this->includeBridge($filters)) {
            return [$audit, $params];
        }

        [$bridgeWhere, $bridgeParams] = $this->bridgeWhere($filters);
        $bridge = "SELECT a.id, a.created_at AS occurred_at, a.user_id AS actor_id, u.name AS actor_label,
                          'import' AS module, a.action, 'ok' AS result, 'import' AS target_type, a.import_id AS target_id,
                          CONCAT('Import #', a.import_id) AS target_label, a.ip_address, NULL AS user_agent, NULL AS request_id,
                          a.details, NULL AS archived_at, 1 AS bridged
                   FROM import_audit_logs a
                   LEFT JOIN users u ON u.id = a.user_id"
            . ($bridgeWhere === [] ? '' : ' WHERE ' . implode(' AND ', $bridgeWhere));

        return ['(' . $audit . ') UNION ALL (' . $bridge . ')', [...$params, ...$bridgeParams]];
    }

    /** @param array<string, mixed> $filters */
    private function includeBridge(array $filters): bool
    {
        if (($filters['archived'] ?? 'active') === 'only') {
            return false;
        }
        $module = $filters['module'] ?? null;
        if ($module !== null && $module !== '' && $module !== 'import') {
            return false;
        }
        $result = $filters['result'] ?? null;

        return $result === null || $result === '' || $result === 'ok';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function bridgeWhere(array $filters): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['from'])) {
            $where[] = 'a.created_at >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'a.created_at <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['actorId'])) {
            $where[] = 'a.user_id = ?';
            $params[] = (int) $filters['actorId'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'a.action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['q'])) {
            $like = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
            $where[] = '(u.name LIKE ? OR a.action LIKE ? OR CONCAT(\'Import #\', a.import_id) LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        return [$where, $params];
    }
}
