<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

/**
 * Marks old rows archived using the retention settings. It never deletes a row.
 * Super Admin runs it from Settings, or from `php bin/apply-retention.php`.
 */
final class RetentionEnforcer
{
    /**
     * @param array<string, mixed>|null $actor null when the command-line job runs
     * @return array{archived: array<string, int>, totalArchived: int, deleted: int}
     */
    public function apply(?array $actor): array
    {
        if ($actor !== null) {
            if (($actor['role_slug'] ?? '') !== 'super_admin' || ($actor['status'] ?? '') !== 'active') {
                throw new \App\Utils\HttpException(403, 'settings_not_editable', 'Only a Super Admin can apply the retention policy.');
            }
        }

        $now = UtcTime::now();
        $pdo = Database::connection();
        $archived = [];

        $archived['audit_logs'] = $this->stamp($pdo, 'audit_logs', 'occurred_at', 'retention.audit_log_days', $now);
        $change = 0;
        $changeCutoff = Retention::cutoff(SecurityPolicy::int('retention.change_history_days', 365, 30, 3650));
        foreach (['entity_changes', 'product_changes', 'master_sheet_changes', 'announcement_changes'] as $table) {
            $change += $this->stampAt($pdo, $table, 'changed_at', $changeCutoff, $now);
        }
        $archived['change_history'] = $change;
        $archived['import_audit'] = $this->stamp($pdo, 'import_audit_logs', 'created_at', 'retention.import_audit_days', $now);
        $archived['login_attempts'] = $this->stamp($pdo, 'login_attempts', 'attempted_at', 'retention.login_attempt_days', $now);
        $archived['privacy_consents'] = $this->stamp($pdo, 'privacy_consents', 'recorded_at', 'retention.consent_days', $now);
        $archived['privacy_requests'] = $this->stamp($pdo, 'privacy_requests', 'requested_at', 'retention.privacy_request_days', $now);

        $total = array_sum($archived);
        $event = [
            'module' => 'system',
            'action' => 'retention_applied',
            'targetType' => 'retention',
            'targetLabel' => 'Retention policy',
            'details' => ['archived' => $archived, 'deleted' => 0],
        ];
        if ($actor === null) {
            $event['actorId'] = null;
            $event['actorLabel'] = 'system';
        }
        AuditLogger::record($event);

        return ['archived' => $archived, 'totalArchived' => $total, 'deleted' => 0];
    }

    private function stamp(\PDO $pdo, string $table, string $column, string $setting, string $now): int
    {
        $days = SecurityPolicy::int($setting, 365, 30, 3650);

        return $this->stampAt($pdo, $table, $column, Retention::cutoff($days), $now);
    }

    private function stampAt(\PDO $pdo, string $table, string $column, string $cutoff, string $now): int
    {
        $stmt = $pdo->prepare(
            "UPDATE {$table} SET archived_at = ? WHERE archived_at IS NULL AND {$column} < ?"
        );
        $stmt->execute([$now, $cutoff]);

        return $stmt->rowCount();
    }
}
