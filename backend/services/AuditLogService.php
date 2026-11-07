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

        return [
            'items' => array_map($this->present(...), $rows),
            'total' => $this->logs->count($filters),
            'limit' => (int) $filters['limit'],
            'offset' => (int) $filters['offset'],
        ];
    }

    /** @param array<string, mixed> $actor */
    public function verify(array $actor): array
    {
        Authorization::authorize($actor, 'audit.view');

        return AuditChain::verify();
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
        $days = Retention::days($input, 'retention.audit_log_days');
        $cutoff = Retention::cutoff($days);
        $archived = $this->logs->archiveOlderThan(UtcTime::now(), $cutoff);
        AuditLogger::record([
            'module' => 'system',
            'action' => 'archive_audit_logs',
            'targetType' => 'audit_logs',
            'targetLabel' => 'Audit logs',
            'details' => ['archived' => $archived, 'olderThanDays' => $days],
        ]);

        return ['archived' => $archived, 'olderThanDays' => $days, 'cutoff' => UtcTime::iso($cutoff)];
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
        $result = $this->list($actor, array_merge($query, ['limit' => '5000', 'offset' => '0']));

        $lines = ['Occurred at (UTC),Actor,Module,Action,Result,Target type,Target id,Target,IP,Request id'];
        foreach ($result['items'] as $item) {
            $lines[] = implode(',', [
                self::csv((string) $item['occurredAt']),
                self::csv(PiiMask::text((string) ($item['actorLabel'] ?? ''))),
                self::csv((string) $item['module']),
                self::csv((string) $item['action']),
                self::csv((string) $item['result']),
                self::csv((string) ($item['targetType'] ?? '')),
                self::csv($item['targetId'] !== null ? (string) $item['targetId'] : ''),
                self::csv(PiiMask::text((string) ($item['targetLabel'] ?? ''))),
                self::csv((string) ($item['ipAddress'] ?? '')),
                self::csv((string) ($item['requestId'] ?? '')),
            ]);
        }

        $filename = 'yorilife-audit-logs-' . (new DateTimeImmutable('now', new DateTimeZone(UtcTime::INDIA)))->format('Ymd_His') . '.csv';
        ExportAudit::record('audit_logs', $filename, count($result['items']));

        return [
            'filename' => $filename,
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
            'actorId' => $actorId,
            'module' => $module,
            'action' => $action,
            'result' => $result,
            'q' => $q === '' ? null : $q,
            'archived' => $archived,
            'limit' => max(1, min(5000, $limit)),
            'offset' => max(0, $offset),
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

        $isBridged = $bridged || (int) ($row['bridged'] ?? 0) === 1 || isset($row['bridge_id']);

        return [
            'id' => $isBridged ? ('import-' . (int) ($row['id'] ?? 0)) : (isset($row['id']) ? (int) $row['id'] : null),
            'bridged' => $isBridged,
            'archived' => !empty($row['archived_at']),
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
        if ($value !== '' && str_contains('=+-@', $value[0])) {
            $value = "'" . $value;
        }
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\t")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
