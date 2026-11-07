<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use PDO;

/**
 * Data access for `users`. Every read method except findForLogin() omits the
 * password hash, so it can't leak into API responses by accident.
 */
final class UserRepository
{
    private const PUBLIC_COLUMNS = 'u.id, u.name, u.email, u.status, u.auth_epoch, u.last_login_at, u.created_at, u.updated_at,
        r.id AS role_id, r.slug AS role_slug, r.name AS role_name';

    private function pdo(): PDO
    {
        return Database::connection();
    }

    /** @return array<string, mixed>|null user without password hash */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT ' . self::PUBLIC_COLUMNS . ' FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** Only for verifying a password at sign-in. @return array<string, mixed>|null */
    public function findForLogin(string $email): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT u.id, u.name, u.status, u.password_hash FROM users u WHERE u.email = ?');
        $stmt->execute([$email]);

        return $stmt->fetch() ?: null;
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ?');
        $stmt->execute([$email, $exceptId ?? 0]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo()
            ->query('SELECT ' . self::PUBLIC_COLUMNS . ' FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.name, u.id')
            ->fetchAll();
    }

    /**
     * Name or email match. Uses the public column list, so the password hash
     * is never selected. $like is a bound pattern, never interpolated.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $like): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.name LIKE ? ESCAPE \'\\\\\' OR u.email LIKE ? ESCAPE \'\\\\\'
             ORDER BY u.name, u.id
             LIMIT 6'
        );
        $stmt->execute([$like, $like]);

        return $stmt->fetchAll();
    }

    public function countByRole(string $roleSlug): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = ?');
        $stmt->execute([$roleSlug]);

        return (int) $stmt->fetchColumn();
    }

    public function create(string $name, string $email, string $passwordHash, int $roleId, string $status = 'active'): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO users (name, email, password_hash, role_id, status) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $email, $passwordHash, $roleId, $status]);

        return (int) $this->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $fields subset of name, email, role_id, status */
    public function update(int $id, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip(['name', 'email', 'role_id', 'status']));
        if ($allowed === []) {
            return;
        }

        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($allowed)));
        $stmt = $this->pdo()->prepare("UPDATE users SET {$assignments} WHERE id = ?");
        $stmt->execute([...array_values($allowed), $id]);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    public function touchLastLogin(int $id): void
    {
        $this->pdo()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }
}
