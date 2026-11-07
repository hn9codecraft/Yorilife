<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Repositories\ApiTokenRepository;
use App\Repositories\UserSessionRepository;
use App\Utils\Session;

/**
 * Ends a user's existing web sessions and API tokens.
 * Used when a password is reset or an account is locked or disabled.
 * The person row is kept. Session files for that user are removed, and
 * auth_epoch is increased so a copied session file cannot keep working.
 */
final class AccessRevoker
{
    /** @return array{sessions: int, tokens: int} */
    public static function revoke(int $userId, bool $keepCurrent = false): array
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE users SET auth_epoch = auth_epoch + 1 WHERE id = ?')->execute([$userId]);
        $epochStmt = $pdo->prepare('SELECT auth_epoch FROM users WHERE id = ?');
        $epochStmt->execute([$userId]);
        $epoch = (int) $epochStmt->fetchColumn();

        $now = UtcTime::now();
        $tokens = (new ApiTokenRepository())->revokeAllForUser($userId, $now);

        $keepId = null;
        if ($keepCurrent) {
            Session::start();
            $current = session_id();
            if (is_string($current) && $current !== '') {
                $keepId = $current;
                $_SESSION['auth_epoch'] = $epoch;
            }
        }

        $sessions = new UserSessionRepository();
        $savePath = (string) Session::config('save_path');
        $removed = 0;
        foreach ($sessions->idsForUser($userId) as $id) {
            if ($keepId !== null && $keepId === $id) {
                continue;
            }
            self::deleteSessionFile($savePath, $id);
            $removed++;
        }
        $sessions->forgetUserExcept($userId, $keepId);
        if ($keepCurrent) {
            Session::close();
        }

        return ['sessions' => $removed, 'tokens' => $tokens];
    }

    private static function deleteSessionFile(string $savePath, string $id): void
    {
        if (preg_match('/^[0-9a-v]{16,128}$/', $id) !== 1) {
            return;
        }
        $file = rtrim($savePath, '/') . '/sess_' . $id;
        if (is_file($file)) {
            unlink($file);
        }
    }
}
