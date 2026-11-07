<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Repositories\EntityChangeRepository;
use App\Utils\HttpException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * System → Change History and Products → Change History.
 * Merges entity_changes with bridged product_changes, master_sheet_changes and
 * announcement_changes. Old tables are never deleted.
 */
final class ChangeHistoryService
{
    public function __construct(private readonly EntityChangeRepository $changes = new EntityChangeRepository())
    {
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $query */
    public function list(array $actor, array $query): array
    {
        Authorization::authorize($actor, 'audit.view');
        $filters = $this->filters($query);
        $limit = (int) $filters['limit'];
        $offset = (int) $filters['offset'];
        $window = min(5000, $offset + $limit);
        $fetch = array_merge($filters, ['limit' => $window, 'offset' => 0]);

        $rows = [];
        $wantTypes = $filters['entityTypes'] ?? null;
        $wantEntity = $filters['entityType'] ?? null;

        $includeEntity = $wantEntity === null && ($wantTypes === null || $wantTypes !== []);
        if ($wantEntity !== null) {
            $includeEntity = true;
        }
        if ($wantTypes !== null) {
            // Still include entity_changes for those types.
            $includeEntity = true;
        }

        if ($includeEntity) {
            foreach ($this->changes->search($fetch) as $row) {
                $rows[] = $this->presentEntity($row);
            }
        }

        $typesFilter = $wantTypes;
        if ($wantEntity !== null) {
            $typesFilter = [$wantEntity];
        }

        $sqlTotal = $includeEntity ? $this->changes->count($filters) : 0;
        if ($this->wants($typesFilter, ['product'])) {
            foreach ($this->bridgeProducts($fetch) as $row) {
                $rows[] = $row;
            }
            $sqlTotal += $this->countSimple('product_changes', $filters);
        }
        if ($this->wants($typesFilter, ['master_sheet_entry'])) {
            foreach ($this->bridgeMasterSheet($fetch) as $row) {
                $rows[] = $row;
            }
            $sqlTotal += $this->countSimple('master_sheet_changes', $filters);
        }
        if ($this->wants($typesFilter, ['announcement'])) {
            foreach ($this->bridgeAnnouncements($fetch) as $row) {
                $rows[] = $row;
            }
            $sqlTotal += $this->countSimple('announcement_changes', $filters);
        }

        if (!empty($filters['changedBy'])) {
            $by = (int) $filters['changedBy'];
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) ($r['changedBy'] ?? 0) === $by));
        }
        if (!empty($filters['field'])) {
            $field = (string) $filters['field'];
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['field'] ?? '') === $field));
        }
        if (!empty($filters['entityId'])) {
            $id = (int) $filters['entityId'];
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) ($r['entityId'] ?? 0) === $id));
        }
        if (!empty($filters['q'])) {
            $q = mb_strtolower((string) $filters['q']);
            $rows = array_values(array_filter($rows, static function (array $r) use ($q): bool {
                $hay = mb_strtolower(implode(' ', [
                    (string) ($r['entityLabel'] ?? ''),
                    (string) ($r['field'] ?? ''),
                    (string) ($r['oldValue'] ?? ''),
                    (string) ($r['newValue'] ?? ''),
                    (string) ($r['changedByName'] ?? ''),
                ]));

                return str_contains($hay, $q);
            }));
        }

        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp((string) $b['changedAt'], (string) $a['changedAt']);

            return $cmp !== 0 ? $cmp : strcmp((string) $b['id'], (string) $a['id']);
        });

        $filtered = !empty($filters['q']) || !empty($filters['field']) || !empty($filters['entityId']) || !empty($filters['changedBy']);
        $total = $filtered ? count($rows) : max(count($rows), $sqlTotal);
        $page = array_slice($rows, $offset, $limit);

        return [
            'items' => $page,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $query @return array{filename: string, content: string} */
    public function exportCsv(array $actor, array $query): array
    {
        Authorization::authorize($actor, 'audit.view');
        $result = $this->list($actor, array_merge($query, ['limit' => '5000', 'offset' => '0']));
        $lines = ['Changed at (UTC),Entity type,Entity id,Entity,Field,Old value,New value,Changed by,Source'];
        foreach ($result['items'] as $item) {
            $lines[] = implode(',', [
                self::csv((string) $item['changedAt']),
                self::csv((string) $item['entityType']),
                self::csv($item['entityId'] !== null ? (string) $item['entityId'] : ''),
                self::csv((string) ($item['entityLabel'] ?? '')),
                self::csv((string) $item['field']),
                self::csv(PiiMask::field((string) $item['field'], (string) ($item['oldValue'] ?? ''))),
                self::csv(PiiMask::field((string) $item['field'], (string) ($item['newValue'] ?? ''))),
                self::csv((string) ($item['changedByName'] ?? '')),
                self::csv((string) ($item['source'] ?? '')),
            ]);
        }

        $stamp = (new DateTimeImmutable('now', new DateTimeZone(UtcTime::INDIA)))->format('Ymd_His');
        $filename = "yorilife-change-history-{$stamp}.csv";
        ExportAudit::record('change_history', $filename, count($result['items']));

        return [
            'filename' => $filename,
            'content' => "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n",
        ];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input */
    public function archive(array $actor, array $input): array
    {
        Authorization::authorize($actor, 'audit.view');
        $errors = [];
        UserValidator::onlyFields($input, ['olderThanDays'], $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $days = Retention::days($input, 'retention.change_history_days');
        $cutoff = Retention::cutoff($days);
        $now = UtcTime::now();
        $archived = 0;
        $pdo = Database::connection();
        foreach (['entity_changes', 'product_changes', 'master_sheet_changes', 'announcement_changes'] as $table) {
            $stmt = $pdo->prepare("UPDATE {$table} SET archived_at = ? WHERE archived_at IS NULL AND changed_at < ?");
            $stmt->execute([$now, $cutoff]);
            $archived += $stmt->rowCount();
        }
        AuditLogger::record([
            'module' => 'system',
            'action' => 'archive_change_history',
            'targetType' => 'entity_changes',
            'targetLabel' => 'Change history',
            'details' => ['archived' => $archived, 'olderThanDays' => $days],
        ]);

        return ['archived' => $archived, 'olderThanDays' => $days, 'cutoff' => UtcTime::iso($cutoff)];
    }

    /** @param list<string>|null $filter @param list<string> $types */
    private function wants(?array $filter, array $types): bool
    {
        if ($filter === null) {
            return true;
        }

        return array_intersect($filter, $types) !== [];
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function filters(array $query): array
    {
        $from = $this->parseIstBoundary($query['from'] ?? null, false);
        $to = $this->parseIstBoundary($query['to'] ?? null, true);
        $changedBy = isset($query['changedBy']) && ctype_digit((string) $query['changedBy']) ? (int) $query['changedBy'] : null;
        $entityType = isset($query['entityType']) && is_string($query['entityType']) && $query['entityType'] !== '' ? $query['entityType'] : null;
        $entityTypes = null;
        if (isset($query['entityTypes']) && is_string($query['entityTypes']) && $query['entityTypes'] !== '') {
            $entityTypes = array_values(array_filter(array_map('trim', explode(',', $query['entityTypes']))));
        }
        if (isset($query['module']) && $query['module'] === 'products') {
            $entityTypes = ['product', 'master_sheet_entry'];
        }
        $entityId = isset($query['entityId']) && ctype_digit((string) $query['entityId']) ? (int) $query['entityId'] : null;
        $field = isset($query['field']) && is_string($query['field']) && $query['field'] !== '' ? $query['field'] : null;
        $q = isset($query['q']) && is_string($query['q']) ? trim($query['q']) : null;
        $limit = isset($query['limit']) && ctype_digit((string) $query['limit']) ? (int) $query['limit'] : SecurityPolicy::recordsPerPage();
        $offset = isset($query['offset']) && ctype_digit((string) $query['offset']) ? (int) $query['offset'] : 0;
        $archived = 'active';
        if (($query['archived'] ?? null) === 'only') {
            $archived = 'only';
        } elseif (($query['archived'] ?? null) === 'all') {
            $archived = 'all';
        }

        return [
            'from' => $from,
            'to' => $to,
            'changedBy' => $changedBy,
            'entityType' => $entityType,
            'entityTypes' => $entityTypes,
            'entityId' => $entityId,
            'field' => $field,
            'q' => $q === '' ? null : $q,
            'archived' => $archived,
            'limit' => max(1, min(5000, $limit)),
            'offset' => max(0, $offset),
        ];
    }

    private function parseIstBoundary(mixed $value, bool $endOfDay): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $time = $endOfDay ? '23:59:59' : '00:00:00';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value . ' ' . $time, new DateTimeZone(UtcTime::INDIA));
        if ($date === false) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    private function bridgeProducts(array $filters): array
    {
        $pdo = Database::connection();
        $where = [];
        $params = [];
        if (!empty($filters['from'])) {
            $where[] = 'c.changed_at >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'c.changed_at <= ?';
            $params[] = $filters['to'];
        }
        $this->appendArchived($where, $filters);
        $sql = 'SELECT c.id, c.product_id, c.product_name, c.action, c.changes, c.changed_at, c.changed_by, u.name AS changed_by_name
                FROM product_changes c LEFT JOIN users u ON u.id = c.changed_by'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . $this->bridgeTail($filters);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $changes = [];
            try {
                $decoded = json_decode((string) $row['changes'], true, 32, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $changes = $decoded;
                }
            } catch (\JsonException) {
                $changes = [];
            }
            if ($changes === []) {
                $out[] = [
                    'id' => 'product-' . $row['id'] . '-action',
                    'bridged' => true,
                    'entityType' => 'product',
                    'entityId' => (int) $row['product_id'],
                    'entityLabel' => $row['product_name'],
                    'field' => 'Action',
                    'oldValue' => null,
                    'newValue' => $row['action'],
                    'changedBy' => $row['changed_by'] !== null ? (int) $row['changed_by'] : null,
                    'changedByName' => $row['changed_by_name'] ?? null,
                    'changedAt' => UtcTime::iso($row['changed_at']),
                    'source' => 'product_changes',
                    'requestId' => null,
                ];
                continue;
            }
            foreach ($changes as $i => $change) {
                $out[] = [
                    'id' => 'product-' . $row['id'] . '-' . $i,
                    'bridged' => true,
                    'entityType' => 'product',
                    'entityId' => (int) $row['product_id'],
                    'entityLabel' => $row['product_name'],
                    'field' => (string) ($change['field'] ?? $row['action']),
                    'oldValue' => isset($change['from']) ? (string) $change['from'] : null,
                    'newValue' => isset($change['to']) ? (string) $change['to'] : null,
                    'changedBy' => $row['changed_by'] !== null ? (int) $row['changed_by'] : null,
                    'changedByName' => $row['changed_by_name'] ?? null,
                    'changedAt' => UtcTime::iso($row['changed_at']),
                    'source' => 'product_changes',
                    'requestId' => null,
                ];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    private function bridgeMasterSheet(array $filters): array
    {
        $pdo = Database::connection();
        $where = [];
        $params = [];
        if (!empty($filters['from'])) {
            $where[] = 'c.changed_at >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'c.changed_at <= ?';
            $params[] = $filters['to'];
        }
        $this->appendArchived($where, $filters);
        $sql = 'SELECT c.id, c.product_id, c.product_name, c.field, c.old_value, c.new_value, c.changed_at, c.changed_by,
                       u.name AS changed_by_name, y.label AS year_label
                FROM master_sheet_changes c
                JOIN financial_years y ON y.id = c.year_id
                LEFT JOIN users u ON u.id = c.changed_by'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . $this->bridgeTail($filters);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id' => 'master-' . $row['id'],
                'bridged' => true,
                'entityType' => 'master_sheet_entry',
                'entityId' => (int) $row['product_id'],
                'entityLabel' => $row['product_name'] . ' (' . $row['year_label'] . ')',
                'field' => $row['field'],
                'oldValue' => $row['old_value'],
                'newValue' => $row['new_value'],
                'changedBy' => $row['changed_by'] !== null ? (int) $row['changed_by'] : null,
                'changedByName' => $row['changed_by_name'] ?? null,
                'changedAt' => UtcTime::iso($row['changed_at']),
                'source' => 'master_sheet_changes',
                'requestId' => null,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    private function bridgeAnnouncements(array $filters): array
    {
        $pdo = Database::connection();
        $where = [];
        $params = [];
        if (!empty($filters['from'])) {
            $where[] = 'c.changed_at >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'c.changed_at <= ?';
            $params[] = $filters['to'];
        }
        $this->appendArchived($where, $filters);
        $sql = 'SELECT c.id, c.announcement_id, c.action, c.changes, c.changed_at, c.changed_by, u.name AS changed_by_name, a.title
                FROM announcement_changes c
                LEFT JOIN announcements a ON a.id = c.announcement_id
                LEFT JOIN users u ON u.id = c.changed_by'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . $this->bridgeTail($filters);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $changes = null;
            if (!empty($row['changes'])) {
                try {
                    $changes = json_decode((string) $row['changes'], true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $changes = null;
                }
            }
            if (is_array($changes) && $changes !== []) {
                $i = 0;
                foreach ($changes as $field => $value) {
                    if (is_array($value) && array_key_exists('from', $value)) {
                        $old = isset($value['from']) ? (is_scalar($value['from']) ? (string) $value['from'] : json_encode($value['from'])) : null;
                        $new = isset($value['to']) ? (is_scalar($value['to']) ? (string) $value['to'] : json_encode($value['to'])) : null;
                        $fieldName = (string) $field;
                    } elseif (is_array($value) && array_key_exists('field', $value)) {
                        $fieldName = (string) $value['field'];
                        $old = isset($value['from']) ? (string) $value['from'] : null;
                        $new = isset($value['to']) ? (string) $value['to'] : null;
                    } else {
                        $fieldName = is_string($field) ? $field : 'change';
                        $old = null;
                        $new = is_scalar($value) ? (string) $value : json_encode($value);
                    }
                    $out[] = [
                        'id' => 'ann-' . $row['id'] . '-' . $i++,
                        'bridged' => true,
                        'entityType' => 'announcement',
                        'entityId' => (int) $row['announcement_id'],
                        'entityLabel' => $row['title'] ?? ('Announcement #' . $row['announcement_id']),
                        'field' => $fieldName,
                        'oldValue' => $old,
                        'newValue' => $new,
                        'changedBy' => $row['changed_by'] !== null ? (int) $row['changed_by'] : null,
                        'changedByName' => $row['changed_by_name'] ?? null,
                        'changedAt' => UtcTime::iso($row['changed_at']),
                        'source' => 'announcement_changes',
                        'requestId' => null,
                    ];
                }
            } else {
                $out[] = [
                    'id' => 'ann-' . $row['id'] . '-action',
                    'bridged' => true,
                    'entityType' => 'announcement',
                    'entityId' => (int) $row['announcement_id'],
                    'entityLabel' => $row['title'] ?? ('Announcement #' . $row['announcement_id']),
                    'field' => 'Action',
                    'oldValue' => null,
                    'newValue' => $row['action'],
                    'changedBy' => $row['changed_by'] !== null ? (int) $row['changed_by'] : null,
                    'changedByName' => $row['changed_by_name'] ?? null,
                    'changedAt' => UtcTime::iso($row['changed_at']),
                    'source' => 'announcement_changes',
                    'requestId' => null,
                ];
            }
        }

        return $out;
    }

    /** @param list<string> $where @param array<string, mixed> $filters */
    private function appendArchived(array &$where, array $filters): void
    {
        $archived = $filters['archived'] ?? 'active';
        if ($archived === 'only') {
            $where[] = 'c.archived_at IS NOT NULL';
        } elseif ($archived !== 'all') {
            $where[] = 'c.archived_at IS NULL';
        }
    }

    /** @param array<string, mixed> $filters */
    private function bridgeTail(array $filters): string
    {
        $limit = max(1, min(5000, (int) ($filters['limit'] ?? 50)));

        return ' ORDER BY c.changed_at DESC, c.id DESC LIMIT ' . $limit;
    }

    /** @param array<string, mixed> $filters */
    private function countSimple(string $table, array $filters): int
    {
        if (!in_array($table, ['product_changes', 'master_sheet_changes', 'announcement_changes'], true)) {
            return 0;
        }
        $where = [];
        $params = [];
        $archived = $filters['archived'] ?? 'active';
        if ($archived === 'only') {
            $where[] = 'archived_at IS NOT NULL';
        } elseif ($archived !== 'all') {
            $where[] = 'archived_at IS NULL';
        }
        if (!empty($filters['from'])) {
            $where[] = 'changed_at >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'changed_at <= ?';
            $params[] = $filters['to'];
        }
        $sql = 'SELECT COUNT(*) AS total FROM ' . $table . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private function presentEntity(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'bridged' => false,
            'entityType' => $row['entity_type'],
            'entityId' => (int) $row['entity_id'],
            'entityLabel' => $row['entity_label'] ?? null,
            'field' => $row['field'],
            'oldValue' => $row['old_value'] ?? null,
            'newValue' => $row['new_value'] ?? null,
            'changedBy' => $row['changed_by'] !== null ? (int) $row['changed_by'] : null,
            'changedByName' => $row['changed_by_name'] ?? null,
            'changedAt' => UtcTime::iso($row['changed_at']),
            'source' => $row['source'] ?? 'app',
            'requestId' => $row['request_id'] ?? null,
        ];
    }

    private static function csv(string $value): string
    {
        if ($value !== '' && str_contains('=+-@', $value[0])) {
            $value = "'" . $value;
        }
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\t")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
