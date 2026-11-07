<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserSessionRepository extends Repository
{
    public function remember(string $sessionId, int $userId, string $now): void
    {
        $this->pdo()->prepare(
            'INSERT INTO user_sessions (id, user_id, created_at) VALUES (?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE user_id = new.user_id, created_at = new.created_at'
        )->execute([$sessionId, $userId, $now]);
    }

    public function forget(string $sessionId): void
    {
        $this->pdo()->prepare('DELETE FROM user_sessions WHERE id = ?')->execute([$sessionId]);
    }

    /** @return list<string> */
    public function idsForUser(int $userId): array
    {
        $ids = [];
        foreach ($this->many('SELECT id FROM user_sessions WHERE user_id = ?', [$userId]) as $row) {
            $ids[] = (string) $row['id'];
        }

        return $ids;
    }

    public function forgetUserExcept(int $userId, ?string $keepId): void
    {
        if ($keepId === null || $keepId === '') {
            $this->pdo()->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$userId]);

            return;
        }
        $this->pdo()->prepare('DELETE FROM user_sessions WHERE user_id = ? AND id <> ?')->execute([$userId, $keepId]);
    }
}
