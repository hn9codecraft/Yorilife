<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use PDO;

/** Authenticator secret, recovery codes and sign-in challenges. Secrets stay in this class. */
final class TwoFactorRepository
{
    private function pdo(): PDO
    {
        return Database::connection();
    }

    /** @return array<string, mixed>|null */
    public function findUser(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT u.id, u.name, u.email, u.status, u.password_hash, u.session_epoch,
                    u.totp_secret, u.totp_pending_secret, u.totp_enabled_at, u.totp_last_step,
                    r.slug AS role_slug
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function savePending(int $id, string $secret): void
    {
        $this->pdo()->prepare('UPDATE users SET totp_pending_secret = ? WHERE id = ?')->execute([$secret, $id]);
    }

    public function enable(int $id, string $secret, int $step): void
    {
        $this->pdo()->prepare(
            'UPDATE users
                SET totp_secret = ?, totp_pending_secret = NULL, totp_enabled_at = UTC_TIMESTAMP(), totp_last_step = ?
              WHERE id = ?'
        )->execute([$secret, $step, $id]);
    }

    public function clear(int $id): void
    {
        $this->pdo()->prepare(
            'UPDATE users
                SET totp_secret = NULL, totp_pending_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL
              WHERE id = ?'
        )->execute([$id]);
    }

    public function setLastStep(int $id, int $step): void
    {
        $this->pdo()->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')->execute([$step, $id]);
    }

    public function retireRecoveryCodes(int $userId): void
    {
        $this->pdo()->prepare(
            'UPDATE user_recovery_codes SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL'
        )->execute([$userId]);
    }

    /** @param list<string> $hashes */
    public function insertRecoveryCodes(int $userId, array $hashes): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO user_recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP())'
        );
        foreach ($hashes as $hash) {
            $stmt->execute([$userId, $hash]);
        }
    }

    public function consumeRecoveryCode(int $userId, string $hash): bool
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE user_recovery_codes
                SET used_at = UTC_TIMESTAMP()
              WHERE user_id = ? AND code_hash = ? AND used_at IS NULL'
        );
        $stmt->execute([$userId, $hash]);

        return $stmt->rowCount() === 1;
    }

    public function unusedRecoveryCount(int $userId): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function issueChallenge(int $userId, string $purpose, int $ttlSeconds): string
    {
        $plain = bin2hex(random_bytes(32));
        $this->pdo()->prepare(
            'INSERT INTO auth_challenges (user_id, token_hash, purpose, expires_at, created_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND, UTC_TIMESTAMP())'
        )->execute([$userId, hash('sha256', $plain), $purpose, $ttlSeconds]);

        return $plain;
    }

    /** @return array<string, mixed>|null */
    public function findChallenge(string $plain): ?array
    {
        if ($plain === '' || strlen($plain) > 128) {
            return null;
        }
        $stmt = $this->pdo()->prepare(
            'SELECT id, user_id, purpose, expires_at, used_at
             FROM auth_challenges
             WHERE token_hash = ?'
        );
        $stmt->execute([hash('sha256', $plain)]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function consumeChallenge(int $id): void
    {
        $this->pdo()->prepare('UPDATE auth_challenges SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL')
            ->execute([$id]);
    }
}
