<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use PDO;

/** Personal device sign-in keys. The plaintext key is never stored. */
final class ApiTokenRepository
{
    private function pdo(): PDO
    {
        return Database::connection();
    }

    /**
     * @return array{id: int, plain: string, prefix: string}
     */
    public function create(int $userId, string $name, string $expiresAt, ?string $ip): array
    {
        $plain = 'ylt_' . bin2hex(random_bytes(32));
        $prefix = substr($plain, 0, 12);
        $this->pdo()->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, token_prefix, expires_at, created_at, created_ip)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)'
        )->execute([$userId, $name, hash('sha256', $plain), $prefix, $expiresAt, $ip]);

        return ['id' => (int) $this->pdo()->lastInsertId(), 'plain' => $plain, 'prefix' => $prefix];
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, name, token_prefix, expires_at, last_used_at, revoked_at, created_at
             FROM api_tokens
             WHERE user_id = ?
             ORDER BY id DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findForUser(int $userId, int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, user_id, name, token_prefix, expires_at, last_used_at, revoked_at, created_at
             FROM api_tokens WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByHash(string $hash): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, user_id, name, token_prefix, expires_at, last_used_at, revoked_at
             FROM api_tokens WHERE token_hash = ?'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function activeCount(int $userId): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM api_tokens
              WHERE user_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function revoke(int $userId, int $id): bool
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP()
              WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$id, $userId]);

        return $stmt->rowCount() === 1;
    }

    public function revokeActive(int $userId): int
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$userId]);

        return $stmt->rowCount();
    }

    public function touchLastUsed(int $id): void
    {
        $this->pdo()->prepare(
            'UPDATE api_tokens
                SET last_used_at = UTC_TIMESTAMP()
              WHERE id = ? AND (last_used_at IS NULL OR last_used_at < UTC_TIMESTAMP() - INTERVAL 60 SECOND)'
        )->execute([$id]);
    }
}
