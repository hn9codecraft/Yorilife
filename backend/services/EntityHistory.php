<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use Throwable;

/**
 * Field-level before/after history for business records (entity_changes).
 * Used for modules that did not already have their own history table.
 * Failures are swallowed so writes never fail because history failed.
 */
final class EntityHistory
{
    /**
     * @param array<string, scalar|null> $before
     * @param array<string, scalar|null> $after
     */
    public static function recordDiff(
        string $entityType,
        int $entityId,
        ?string $entityLabel,
        array $before,
        array $after,
        ?int $changedBy = null,
        string $source = 'app',
        ?string $changedAt = null,
    ): void {
        $fields = [];
        foreach ($after as $field => $newValue) {
            $oldValue = $before[$field] ?? null;
            $old = self::stringify($oldValue);
            $new = self::stringify($newValue);
            if ($old !== $new) {
                $fields[] = ['field' => (string) $field, 'old' => $old, 'new' => $new];
            }
        }
        // Fields removed entirely.
        foreach ($before as $field => $oldValue) {
            if (!array_key_exists($field, $after)) {
                $old = self::stringify($oldValue);
                if ($old !== null) {
                    $fields[] = ['field' => (string) $field, 'old' => $old, 'new' => null];
                }
            }
        }
        if ($fields === []) {
            return;
        }
        self::recordMany($entityType, $entityId, $entityLabel, $fields, $changedBy, $source, $changedAt);
    }

    /**
     * @param list<array{field: string, old: ?string, new: ?string}> $fields
     */
    public static function recordMany(
        string $entityType,
        int $entityId,
        ?string $entityLabel,
        array $fields,
        ?int $changedBy = null,
        string $source = 'app',
        ?string $changedAt = null,
    ): void {
        if ($fields === []) {
            return;
        }
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'INSERT INTO entity_changes (
                    entity_type, entity_id, entity_label, field, old_value, new_value,
                    changed_by, changed_at, source, request_id
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $at = $changedAt ?? UtcTime::now();
            $by = $changedBy ?? RequestContext::actorId();
            $label = $entityLabel === null ? null : mb_substr($entityLabel, 0, 255);
            $requestId = RequestContext::requestId();
            foreach ($fields as $row) {
                $stmt->execute([
                    mb_substr($entityType, 0, 40),
                    $entityId,
                    $label,
                    mb_substr((string) $row['field'], 0, 100),
                    self::clip($row['old'] ?? null),
                    self::clip($row['new'] ?? null),
                    $by,
                    $at,
                    mb_substr($source, 0, 40),
                    $requestId,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[audit] failed to write entity_changes: ' . $e->getMessage());
        }
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            try {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return null;
            }
        }

        return (string) $value;
    }

    private static function clip(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > 65000 ? mb_substr($value, 0, 65000) : $value;
    }
}
