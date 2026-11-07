<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Utils\HttpException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * System → Audit Logs: list, detail and CSV export. Super Admin / Admin only.
 * Bridges import_audit_logs so older import actions appear alongside new rows.
 */
final class AuditLogService
{
    public function __construct(private readonly AuditLogRepository $logs = new AuditLogRepository())
    {
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $query */
    public function list(array $actor, array $query): array
    {
        Authorization::authorize($actor, 'audit.view');
        $filters = $this->filters($query);
        $rows = $this->logs->search($filters);
        $total = $this->logs->count($filters);

        // Bridge import audit when not filtering to a non-import module.
        $bridged = [];
        if (($filters['module'] ?? null) === null || ($filters['module'] ?? null) === 'import') {
            $bridged = $this->logs->bridgedImports($filters['from'] ?? null, $filters['to'] ?? null, 200);
            if (!empty($filters['actorId'])) {
                $bridged = array_values(array_filter(
                    $bridged,
                    static fn (array $row): bool => (int) ($row['actor_id'] ?? 0) === (int) $filters['actorId']
                ));
            }
            if (!empty($filters['action'])) {
                $bridged = array_values(array_filter(
                    $bridged,
                    static fn (array $row): bool => ($row['action'] ?? '') === $filters['action']
                ));
            }
            if (!empty($filters['q'])) {
                $q = mb_strtolower((string) $filters['q']);
                $bridged = array_values(array_filter(
                    $bridged,
                    static function (array $row) use ($q): bool {
                        $hay = mb_strtolower(implode(' ', [
                            (string) ($row['actor_label'] ?? ''),
                            (string) ($row['target_label'] ?? ''),
                            (string) ($row['action'] ?? ''),
                            (string) ($row['module'] ?? ''),
                        ]));

                        return str_contains($hay, $q);
                    }
                ));
            }
        }

        $merged = array_map($this->present(...), $rows);
        foreach ($bridged as $row) {
            $merged[] = $this->present($row, true);
        }
        usort($merged, static fn (array $a, array $b): int => strcmp($b['occurredAt'], $a['occurredAt']));
        $limit = (int) ($filters['limit'] ?? 50);
        $offset = (int) ($filters['offset'] ?? 0);
        $page = array_slice($merged, $offset, $limit);

        return [
            'items' => $page,
            'total' => $total + count($bridged),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /** @param array<string, mixed> $actor */
    public function get(array $actor, int $id): array
    {
        Authorization::authorize($actor, 'audit.view');
        $row = $this->logs->find($id) ?? throw HttpException::notFound('Audit log entry not found.');

        return $this->present($row);
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $query @return array{filename: string, content: string} */
    public function exportCsv(array $actor, array $query): array
    {
        Authorization::authorize($actor, 'audit.view');
        $filters = $this->filters($query);
        $filters['limit'] = 5000;
        $filters['offset'] = 0;
        $result = $this->list($actor, array_merge($query, ['limit' => '5000', 'offset' => '0']));

        $lines = ['Occurred at (UTC),Actor,Module,Action,Result,Target type,Target id,Target,IP,Request id'];
        foreach ($result['items'] as $item) {
            $lines[] = implode(',', [
                self::csv($item['occurredAt']),
                self::csv($item['actorLabel'] ?? ''),
                self::csv($item['module']),
                self::csv($item['action']),
                self::csv($item['result']),
                self::csv($item['targetType'] ?? ''),
                self::csv($item['targetId'] !== null ? (string) $item['targetId'] : ''),
                self::csv($item['targetLabel'] ?? ''),
                self::csv($item['ipAddress'] ?? ''),
                self::csv($item['requestId'] ?? ''),
            ]);
        }

        AuditLogger::record([
            'module' => 'system',
            'action' => 'audit_export',
            'targetType' => 'audit_logs',
            'targetLabel' => 'Audit Logs CSV',
            'details' => ['rowCount' => count($result['items'])],
        ]);

        $stamp = (new DateTimeImmutable('now', new DateTimeZone(UtcTime::INDIA)))->format('Ymd_His');

        return [
            'filename' => "yorilife-audit-logs-{$stamp}.csv",
            'content' => "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n",
        ];
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function filters(array $query): array
    {
        $from = $this->parseIstBoundary($query['from'] ?? null, false);
        $to = $this->parseIstBoundary($query['to'] ?? null, true);
        $actorId = isset($query['actorId']) && ctype_digit((string) $query['actorId']) ? (int) $query['actorId'] : null;
        $module = isset($query['module']) && is_string($query['module']) && $query['module'] !== '' ? $query['module'] : null;
        $action = isset($query['action']) && is_string($query['action']) && $query['action'] !== '' ? $query['action'] : null;
        $result = isset($query['result']) && in_array($query['result'], ['ok', 'denied', 'failed'], true) ? $query['result'] : null;
        $q = isset($query['q']) && is_string($query['q']) ? trim($query['q']) : null;
        $limit = isset($query['limit']) && ctype_digit((string) $query['limit']) ? (int) $query['limit'] : 50;
        $offset = isset($query['offset']) && ctype_digit((string) $query['offset']) ? (int) $query['offset'] : 0;

        return [
            'from' => $from,
            'to' => $to,
            'actorId' => $actorId,
            'module' => $module,
            'action' => $action,
            'result' => $result,
            'q' => $q === '' ? null : $q,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /** Date-only IST input → UTC datetime string for the start or end of that day. */
    private function parseIstBoundary(mixed $value, bool $endOfDay): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $time = $endOfDay ? '23:59:59' : '00:00:00';
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value . ' ' . $time,
            new DateTimeZone(UtcTime::INDIA)
        );
        if ($date === false) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private function present(array $row, bool $bridged = false): array
    {
        $details = $row['details'] ?? null;
        if (is_string($details) && $details !== '') {
            try {
                $details = json_decode($details, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $details = null;
            }
        }

        return [
            'id' => isset($row['id']) ? (int) $row['id'] : ($row['bridge_id'] ?? null),
            'bridged' => $bridged || isset($row['bridge_id']),
            'occurredAt' => UtcTime::iso($row['occurred_at'] ?? null),
            'actorId' => isset($row['actor_id']) && $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
            'actorLabel' => $row['actor_label'] ?? null,
            'module' => $row['module'],
            'action' => $row['action'],
            'result' => $row['result'] ?? 'ok',
            'targetType' => $row['target_type'] ?? null,
            'targetId' => isset($row['target_id']) && $row['target_id'] !== null ? (int) $row['target_id'] : null,
            'targetLabel' => $row['target_label'] ?? null,
            'ipAddress' => $row['ip_address'] ?? null,
            'userAgent' => $row['user_agent'] ?? null,
            'requestId' => $row['request_id'] ?? null,
            'details' => is_array($details) ? $details : null,
        ];
    }

    private static function csv(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n") || str_contains($value, "\r")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
