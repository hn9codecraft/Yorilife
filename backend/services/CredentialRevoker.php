<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ApiTokenRepository;
use App\Repositories\UserRepository;

/**
 * Ends a person's existing web sessions and device sign-in keys.
 * Session files are not scanned: the account's session epoch is increased,
 * and the next request with an older cookie is rejected.
 */
final class CredentialRevoker
{
    /** @return array{sessionsRevoked: true, devicesRevoked: int} */
    public static function revoke(int $userId): array
    {
        (new UserRepository())->bumpSessionEpoch($userId);

        return [
            'sessionsRevoked' => true,
            'devicesRevoked' => (new ApiTokenRepository())->revokeActive($userId),
        ];
    }
}
