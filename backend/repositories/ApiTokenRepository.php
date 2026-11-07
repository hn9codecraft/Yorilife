<?php

declare(strict_types=1);

namespace App\Repositories;

final class ApiTokenRepository extends Repository
{
    /** @return array<string, mixed>|null */
    public function findByHash(string $hash): ?array
    {
        return $this->one(
            'SELECT id, user_id, name, token_prefix, expires_at, last_used_at, revoked_at, created_at
             FROM api_tokens WHERE token_hash = ?',
            [$hash]
        );
    }

    /** @return array<string, mixed>|null */
    public function findForUser(int $userId, int $id): ?array
    {
        return $this->one(
            'SELECT id, user_id, name, token_prefix, expires_at, last_used_at, revoked_at, created_at
             FROM api_tokens WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        return $this->many(
            'SELECT id, user_id, name, token_prefix, expires_at, last_used_at, revoked_at, created_at
             FROM api_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 50',
            [$userId]
        );
    }

    public function countActive(int $userId, string $now): int
    {
        $row = $this->one(
            'SELECT COUNT(*) AS total FROM api_tokens
             WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ?',
            [$userId, $now]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function insert(int $userId, string $name, string $hash, string $prefix, string $expiresAt, string $now): int
    {
        return $this->insertRow('api_tokens', [
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => $hash,
            'token_prefix' => $prefix,
            'expires_at' => $expiresAt,
            'last_used_at' => null,
            'revoked_at' => null,
            'created_at' => $now,
        ]);
    }

    public function touchLastUsed(int $id, string $now): void
    {
        $this->pdo()->prepare('UPDATE api_tokens SET last_used_at = ? WHERE id = ?')->execute([$now, $id]);
    }

    public function revoke(int $userId, int $id, string $now): bool
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$now, $id, $userId]);

        return $stmt->rowCount() > 0;
    }

    public function revokeAllForUser(int $userId, string $now): int
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE api_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$now, $userId]);

        return $stmt->rowCount();
    }
}
