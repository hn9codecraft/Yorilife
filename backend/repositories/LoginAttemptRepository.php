<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class LoginAttemptRepository
{
    public function record(string $identifierHash, string $ip, bool $succeeded): void
    {
        Database::connection()
            ->prepare('INSERT INTO login_attempts (identifier_hash, ip_address, succeeded, attempted_at) VALUES (?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$identifierHash, $ip, $succeeded ? 1 : 0]);
    }

    /**
     * Failed attempts within the window, and seconds until the oldest of them
     * leaves the window (for Retry-After).
     *
     * @return array{failures: int, retry_after: int}
     */
    public function recentFailures(string $column, string $value, int $windowMinutes): array
    {
        $column = $column === 'ip_address' ? 'ip_address' : 'identifier_hash';
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) AS failures,
                    COALESCE(TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), MIN(attempted_at) + INTERVAL ? MINUTE), 0) AS retry_after
               FROM login_attempts
              WHERE {$column} = ? AND succeeded = 0 AND cleared_at IS NULL
                AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE"
        );
        $stmt->execute([$windowMinutes, $value, $windowMinutes]);
        $row = $stmt->fetch();

        return ['failures' => (int) $row['failures'], 'retry_after' => max(1, (int) $row['retry_after'])];
    }

    /** A successful sign-in stops counting earlier failures. The rows are kept. */
    public function clearFailures(string $identifierHash): void
    {
        Database::connection()
            ->prepare('UPDATE login_attempts SET cleared_at = UTC_TIMESTAMP() WHERE identifier_hash = ? AND succeeded = 0 AND cleared_at IS NULL')
            ->execute([$identifierHash]);
    }

    /** Mark old attempts archived. Rows are not deleted; retention uses this too. */
    public function pruneOlderThanDays(int $days): void
    {
        Database::connection()
            ->prepare('UPDATE login_attempts SET archived_at = UTC_TIMESTAMP() WHERE archived_at IS NULL AND attempted_at < UTC_TIMESTAMP() - INTERVAL ? DAY')
            ->execute([$days]);
    }
}
