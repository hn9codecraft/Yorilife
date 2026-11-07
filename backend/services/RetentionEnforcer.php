<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

/**
 * Applies the retention settings by marking old rows archived.
 * It never deletes a row. Each data type uses its own number of days.
 */
final class RetentionEnforcer
{
    /** @param array<string, mixed>|null $actor null when run from the command line */
    public static function apply(?array $actor): array
    {
        if ($actor !== null) {
            Authorization::authorize($actor, 'settings.edit');
        }
        $now = UtcTime::now();
        $changeDays = SecurityPolicy::int('retention.change_history_days', 365, 30, 3650);
        $privacyDays = SecurityPolicy::int('retention.privacy_record_days', 365, 30, 3650);
        $archived = [
            'auditLogs' => self::archive('audit_logs', 'occurred_at', SecurityPolicy::int('retention.audit_log_days', 365, 30, 3650), $now),
            'changeHistory' => self::archive('entity_changes', 'changed_at', $changeDays, $now)
                + self::archive('product_changes', 'changed_at', $changeDays, $now)
                + self::archive('master_sheet_changes', 'changed_at', $changeDays, $now)
                + self::archive('announcement_changes', 'changed_at', $changeDays, $now),
            'importAudit' => self::archive('import_audit_logs', 'created_at', SecurityPolicy::int('retention.import_audit_days', 365, 30, 3650), $now),
            'loginAttempts' => self::archive('login_attempts', 'attempted_at', SecurityPolicy::int('retention.login_attempt_days', 365, 30, 3650), $now),
            'privacyRecords' => self::archive('privacy_consents', 'recorded_at', $privacyDays, $now)
                + self::archive('privacy_requests', 'requested_at', $privacyDays, $now),
        ];
        $event = [
            'module' => 'system',
            'action' => 'retention_apply',
            'targetType' => 'settings',
            'targetLabel' => 'Retention',
            'details' => $archived,
        ];
        if ($actor === null) {
            $event['actorId'] = null;
            $event['actorLabel'] = 'system';
        }
        AuditLogger::record($event);

        return ['archived' => $archived, 'deleted' => 0];
    }

    private static function archive(string $table, string $column, int $days, string $now): int
    {
        $stmt = Database::connection()->prepare(
            "UPDATE {$table} SET archived_at = ? WHERE archived_at IS NULL AND {$column} < ?"
        );
        $stmt->execute([$now, Retention::cutoff($days)]);

        return $stmt->rowCount();
    }
}
