<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Authenticator secret and one-time recovery-code hashes.
 * The plaintext secret leaves this repository only for setup and verification.
 */
final class TwoFactorRepository extends Repository
{
    public function isEnabled(int $userId): bool
    {
        $row = $this->one('SELECT enabled_at FROM user_totp WHERE user_id = ?', [$userId]);

        return $row !== null && $row['enabled_at'] !== null;
    }

    public function enabledSecret(int $userId): ?string
    {
        $row = $this->one('SELECT secret FROM user_totp WHERE user_id = ? AND enabled_at IS NOT NULL', [$userId]);

        return isset($row['secret']) ? (string) $row['secret'] : null;
    }

    public function pendingSecret(int $userId): ?string
    {
        $row = $this->one('SELECT secret FROM user_totp WHERE user_id = ? AND enabled_at IS NULL', [$userId]);

        return isset($row['secret']) ? (string) $row['secret'] : null;
    }

    public function savePending(int $userId, string $secret, string $now): void
    {
        $this->pdo()->prepare(
            'INSERT INTO user_totp (user_id, secret, enabled_at, created_at) VALUES (?, ?, NULL, ?) AS new
             ON DUPLICATE KEY UPDATE secret = new.secret, enabled_at = NULL, created_at = new.created_at'
        )->execute([$userId, $secret, $now]);
    }

    public function enable(int $userId, string $now): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE user_totp SET enabled_at = ? WHERE user_id = ? AND enabled_at IS NULL');
        $stmt->execute([$now, $userId]);

        return $stmt->rowCount() > 0;
    }

    public function clear(int $userId): void
    {
        $this->pdo()->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$userId]);
        $this->pdo()->prepare('DELETE FROM user_totp WHERE user_id = ?')->execute([$userId]);
    }

    /** @param list<string> $hashes */
    public function replaceRecoveryHashes(int $userId, array $hashes, string $now): void
    {
        $pdo = $this->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$userId]);
            $insert = $pdo->prepare(
                'INSERT INTO user_recovery_codes (user_id, code_hash, used_at, created_at) VALUES (?, ?, NULL, ?)'
            );
            foreach ($hashes as $hash) {
                $insert->execute([$userId, $hash, $now]);
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function consumeRecoveryHash(int $userId, string $hash, string $now): bool
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE user_recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL'
        );
        $stmt->execute([$now, $userId, $hash]);

        return $stmt->rowCount() > 0;
    }

    public function recoveryRemaining(int $userId): int
    {
        $row = $this->one(
            'SELECT COUNT(*) AS total FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );

        return (int) ($row['total'] ?? 0);
    }
}
