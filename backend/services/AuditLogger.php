<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use Throwable;

/**
 * Append-only company audit log. Failures are swallowed and logged to the
 * PHP error log so a write path never fails because auditing failed.
 * Never pass passwords, tokens or full file bodies in $details.
 */
final class AuditLogger
{
    /**
     * @param array{
     *   module: string,
     *   action: string,
     *   result?: 'ok'|'denied'|'failed',
     *   targetType?: ?string,
     *   targetId?: ?int,
     *   targetLabel?: ?string,
     *   actorId?: ?int,
     *   actorLabel?: ?string,
     *   details?: array<string, mixed>|null,
     *   occurredAt?: ?string
     * } $event
     */
    public static function record(array $event): void
    {
        try {
            $actorId = array_key_exists('actorId', $event) ? $event['actorId'] : RequestContext::actorId();
            $actorLabel = array_key_exists('actorLabel', $event) ? $event['actorLabel'] : RequestContext::actorLabel();
            if ($actorLabel === null && $actorId === null && ($event['actorLabel'] ?? null) === 'system') {
                $actorLabel = 'system';
            }

            $details = $event['details'] ?? null;
            if (is_array($details)) {
                $details = self::scrub($details);
            }

            AuditChain::append(Database::connection(), [
                'occurred_at' => $event['occurredAt'] ?? UtcTime::now(),
                'actor_id' => $actorId,
                'actor_label' => $actorLabel === null ? null : mb_substr((string) $actorLabel, 0, 150),
                'module' => mb_substr((string) $event['module'], 0, 40),
                'action' => mb_substr((string) $event['action'], 0, 80),
                'result' => $event['result'] ?? 'ok',
                'target_type' => isset($event['targetType']) && $event['targetType'] !== null && $event['targetType'] !== ''
                    ? mb_substr((string) $event['targetType'], 0, 40)
                    : null,
                'target_id' => $event['targetId'] ?? null,
                'target_label' => isset($event['targetLabel']) && $event['targetLabel'] !== null && $event['targetLabel'] !== ''
                    ? mb_substr((string) $event['targetLabel'], 0, 255)
                    : null,
                'ip_address' => RequestContext::ip(),
                'user_agent' => RequestContext::userAgent(),
                'request_id' => RequestContext::requestId(),
                'details' => $details === null || $details === []
                    ? null
                    : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $e) {
            error_log('[audit] failed to write audit_logs: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $details @return array<string, mixed> */
    private static function scrub(array $details): array
    {
        $blocked = ['password', 'password_hash', 'token', 'csrf', 'csrf_token', 'secret', 'authorization'];
        $clean = [];
        foreach ($details as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, $blocked, true) || str_contains($lower, 'password') || str_contains($lower, 'token')) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::scrub($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = (string) $value;
            }
        }

        return $clean;
    }
}
