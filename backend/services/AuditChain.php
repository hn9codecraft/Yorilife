<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use PDO;
use Throwable;

/**
 * Tamper-evident hash chain for audit_logs.
 *
 * Each row stores the previous row's hash and its own SHA-256 over a canonical
 * payload. Verification walks every row in id order, including archived rows,
 * then compares the tip with audit_chain_head. Deleting the newest rows leaves
 * a valid-looking prefix, but the separately stored head no longer matches.
 * Archiving does not change hashed columns. The application never deletes rows.
 */
final class AuditChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** @param array<string, mixed> $row */
    public static function hash(array $row, string $prevHash): string
    {
        $details = $row['details'] ?? null;
        if ($details !== null && !is_string($details)) {
            $encoded = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $details = $encoded === false ? null : $encoded;
        }

        $payload = [
            'v' => 1,
            'id' => (int) $row['id'],
            'occurred_at' => (string) $row['occurred_at'],
            'actor_id' => self::nullableInt($row['actor_id'] ?? null),
            'actor_label' => self::nullableString($row['actor_label'] ?? null),
            'module' => (string) $row['module'],
            'action' => (string) $row['action'],
            'result' => (string) ($row['result'] ?? 'ok'),
            'target_type' => self::nullableString($row['target_type'] ?? null),
            'target_id' => self::nullableInt($row['target_id'] ?? null),
            'target_label' => self::nullableString($row['target_label'] ?? null),
            'ip_address' => self::nullableString($row['ip_address'] ?? null),
            'user_agent' => self::nullableString($row['user_agent'] ?? null),
            'request_id' => self::nullableString($row['request_id'] ?? null),
            'details' => $details === null ? null : (string) $details,
            'prev_hash' => $prevHash,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Insert one audit row and extend the chain. Joins an open transaction so a
     * rolled-back business write rolls the audit row back with it.
     *
     * @param array<string, mixed> $columns
     */
    public static function append(PDO $pdo, array $columns): void
    {
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            self::ensureHead($pdo);
            $head = $pdo->query('SELECT last_hash FROM audit_chain_head WHERE id = 1 FOR UPDATE')->fetch();
            $prev = is_array($head) && is_string($head['last_hash']) && strlen($head['last_hash']) === 64
                ? $head['last_hash']
                : self::GENESIS;

            $pdo->prepare(
                'INSERT INTO audit_logs (
                    occurred_at, actor_id, actor_label, module, action, result,
                    target_type, target_id, target_label, ip_address, user_agent, request_id, details
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $columns['occurred_at'],
                $columns['actor_id'],
                $columns['actor_label'],
                $columns['module'],
                $columns['action'],
                $columns['result'],
                $columns['target_type'],
                $columns['target_id'],
                $columns['target_label'],
                $columns['ip_address'],
                $columns['user_agent'],
                $columns['request_id'],
                $columns['details'],
            ]);
            $id = (int) $pdo->lastInsertId();
            $stored = $pdo->prepare(
                'SELECT id, occurred_at, actor_id, actor_label, module, action, result,
                        target_type, target_id, target_label, ip_address, user_agent, request_id, details
                 FROM audit_logs WHERE id = ?'
            );
            $stored->execute([$id]);
            $row = $stored->fetch();
            if (!is_array($row)) {
                throw new \RuntimeException('Audit row missing after insert.');
            }
            $rowHash = self::hash($row, $prev);
            $pdo->prepare('UPDATE audit_logs SET prev_hash = ?, row_hash = ? WHERE id = ?')->execute([$prev, $rowHash, $id]);
            $pdo->prepare('UPDATE audit_chain_head SET last_log_id = ?, last_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = 1')
                ->execute([$id, $rowHash]);
            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Hash rows that were stored before the chain existed. Sealed rows are left as they are. */
    public static function sealUnsealed(): int
    {
        $pdo = Database::connection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            self::ensureHead($pdo);
            $pdo->query('SELECT id FROM audit_chain_head WHERE id = 1 FOR UPDATE');
            $rows = $pdo->query(
                'SELECT id, occurred_at, actor_id, actor_label, module, action, result,
                        target_type, target_id, target_label, ip_address, user_agent, request_id, details
                 FROM audit_logs WHERE row_hash IS NULL ORDER BY id ASC'
            )->fetchAll();
            $sealed = 0;
            $prev = self::GENESIS;
            if ($rows !== []) {
                $before = $pdo->prepare('SELECT row_hash FROM audit_logs WHERE id < ? AND row_hash IS NOT NULL ORDER BY id DESC LIMIT 1');
                $before->execute([(int) $rows[0]['id']]);
                $found = $before->fetchColumn();
                if (is_string($found) && strlen($found) === 64) {
                    $prev = $found;
                }
            }
            $update = $pdo->prepare('UPDATE audit_logs SET prev_hash = ?, row_hash = ? WHERE id = ? AND row_hash IS NULL');
            foreach ($rows as $row) {
                $hash = self::hash($row, $prev);
                $update->execute([$prev, $hash, (int) $row['id']]);
                $sealed += $update->rowCount();
                $prev = $hash;
            }
            $last = $pdo->query('SELECT id, row_hash FROM audit_logs WHERE row_hash IS NOT NULL ORDER BY id DESC LIMIT 1')->fetch();
            if (is_array($last) && is_string($last['row_hash']) && $last['row_hash'] !== '') {
                $pdo->prepare('UPDATE audit_chain_head SET last_log_id = ?, last_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = 1')
                    ->execute([(int) $last['id'], $last['row_hash']]);
            } else {
                $pdo->prepare('UPDATE audit_chain_head SET last_log_id = NULL, last_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = 1')
                    ->execute([self::GENESIS]);
            }
            if ($own) {
                $pdo->commit();
            }

            return $sealed;
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{intact: bool, checked: int, brokenAt: int|null, reason: string|null} */
    public static function verify(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            'SELECT id, occurred_at, actor_id, actor_label, module, action, result,
                    target_type, target_id, target_label, ip_address, user_agent, request_id, details,
                    prev_hash, row_hash
             FROM audit_logs ORDER BY id ASC'
        );
        $prev = self::GENESIS;
        $checked = 0;
        $tipId = null;
        while ($row = $stmt->fetch()) {
            $checked++;
            $id = (int) $row['id'];
            $tipId = $id;
            if (!is_string($row['row_hash']) || strlen($row['row_hash']) !== 64 || !is_string($row['prev_hash']) || strlen($row['prev_hash']) !== 64) {
                return self::broken($checked, $id, 'unsealed');
            }
            if (!hash_equals($prev, $row['prev_hash'])) {
                return self::broken($checked, $id, 'prev_mismatch');
            }
            if (!hash_equals(self::hash($row, $prev), $row['row_hash'])) {
                return self::broken($checked, $id, 'hash_mismatch');
            }
            $prev = $row['row_hash'];
        }

        $head = $pdo->query('SELECT last_log_id, last_hash FROM audit_chain_head WHERE id = 1')->fetch();
        if (!is_array($head) || !is_string($head['last_hash'])) {
            return self::broken($checked, $tipId ?? 0, 'head_missing');
        }
        $headId = $head['last_log_id'] === null ? null : (int) $head['last_log_id'];
        $tipHash = $checked === 0 ? self::GENESIS : $prev;
        if ($headId !== $tipId || !hash_equals($tipHash, $head['last_hash'])) {
            $reason = $headId !== $tipId ? 'tail_truncated' : 'head_mismatch';

            return self::broken($checked === 0 ? 1 : $checked, $headId ?? $tipId ?? 0, $reason);
        }

        return ['intact' => true, 'checked' => $checked, 'brokenAt' => null, 'reason' => null];
    }

    /** @return array{intact: bool, checked: int, brokenAt: int, reason: string} */
    private static function broken(int $checked, int $id, string $reason): array
    {
        return ['intact' => false, 'checked' => $checked, 'brokenAt' => $id, 'reason' => $reason];
    }

    private static function ensureHead(PDO $pdo): void
    {
        $pdo->prepare(
            'INSERT IGNORE INTO audit_chain_head (id, last_log_id, last_hash, updated_at) VALUES (1, NULL, ?, UTC_TIMESTAMP())'
        )->execute([self::GENESIS]);
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = (string) $value;

        return $text === '' ? null : $text;
    }
}
