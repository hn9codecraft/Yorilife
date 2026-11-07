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
        [$where, $params] = $this->buildWhere($filters);
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $sql = 'SELECT id, occurred_at, actor_id, actor_label, module, action, result,
                       target_type, target_id, target_label, ip_address, user_agent, request_id, details
                FROM audit_logs'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY occurred_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        return $this->many($sql, $params);
    }

    /** @param array<string, mixed> $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = 'SELECT COUNT(*) AS total FROM audit_logs'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
        $row = $this->one($sql, $params);

        return (int) ($row['total'] ?? 0);
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

        return [$where, $params];
    }
}
