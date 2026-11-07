<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ApiTokenRepository;
use App\Repositories\TwoFactorRepository;
use App\Repositories\UserRepository;
use App\Utils\HttpException;

/**
 * Personal device tokens for a future mobile app.
 * The plaintext token is returned once, at creation. Later requests send it as
 * Authorization: Bearer. Only its SHA-256 hash is stored. Create, revoke,
 * first use and rejected use are audited. The token value is not.
 */
final class ApiTokenService
{
    public function __construct(
        private readonly ApiTokenRepository $tokens = new ApiTokenRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /** @param array<string, mixed> $actor @return list<array<string, mixed>> */
    public function list(array $actor): array
    {
        return array_map($this->present(...), $this->tokens->listForUser((int) $actor['id']));
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input { name, expiresInDays? }
     * @return array{token: string, apiToken: array<string, mixed>}
     */
    public function create(array $actor, array $input): array
    {
        $errors = [];
        UserValidator::onlyFields($input, ['name', 'expiresInDays'], $errors);
        $name = $this->name($input['name'] ?? null, $errors);
        $days = $this->days($input, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $userId = (int) $actor['id'];
        $now = UtcTime::now();
        if ($this->tokens->countActive($userId, $now) >= 10) {
            throw HttpException::validation(['name' => 'This account already has 10 active device tokens. Revoke one first.']);
        }

        $plain = 'ylt_' . bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($days * 86400));
        $id = $this->tokens->insert($userId, $name, hash('sha256', $plain), substr($plain, 0, 12), $expiresAt, $now);
        $row = $this->tokens->findForUser($userId, $id) ?? throw HttpException::notFound('Device token not found.');
        AuditLogger::record([
            'module' => 'auth',
            'action' => 'token_create',
            'targetType' => 'api_token',
            'targetId' => $id,
            'targetLabel' => $name,
            'details' => ['prefix' => $row['token_prefix'], 'expiresAt' => UtcTime::iso($expiresAt)],
        ]);

        return ['token' => $plain, 'apiToken' => $this->present($row)];
    }

    /** @param array<string, mixed> $actor */
    public function revoke(array $actor, int $id): array
    {
        $userId = (int) $actor['id'];
        $row = $this->tokens->findForUser($userId, $id) ?? throw HttpException::notFound('Device token not found.');
        $now = UtcTime::now();
        if ($row['revoked_at'] === null) {
            $this->tokens->revoke($userId, $id, $now);
            $row['revoked_at'] = $now;
            AuditLogger::record([
                'module' => 'auth',
                'action' => 'token_revoke',
                'targetType' => 'api_token',
                'targetId' => $id,
                'targetLabel' => $row['name'],
                'details' => ['prefix' => $row['token_prefix']],
            ]);
        }

        return $this->present($row);
    }

    /** @return array<string, mixed> signed-in user, without the token */
    public function authenticate(string $plain): array
    {
        RequestContext::setActor(null);
        if ($plain === '' || strlen($plain) > 200 || !str_starts_with($plain, 'ylt_')) {
            $this->reject('malformed');
        }
        $row = $this->tokens->findByHash(hash('sha256', $plain));
        if ($row === null) {
            $this->reject('unknown');
        }
        if ($row['revoked_at'] !== null) {
            $this->reject('revoked', (int) $row['id']);
        }
        if ((string) $row['expires_at'] <= UtcTime::now()) {
            $this->reject('expired', (int) $row['id']);
        }
        $user = $this->users->find((int) $row['user_id']);
        if ($user === null || $user['status'] !== 'active') {
            $this->reject('inactive', (int) $row['id']);
        }
        if (SecurityPolicy::totpRequiredFor((string) $user['role_slug']) && !(new TwoFactorRepository())->isEnabled((int) $user['id'])) {
            throw HttpException::forbidden(
                'Set up an authenticator app before using this device token.',
                'two_factor_enrollment_required',
            );
        }

        $firstUse = $row['last_used_at'] === null;
        $this->tokens->touchLastUsed((int) $row['id'], UtcTime::now());
        if ($firstUse) {
            RequestContext::setActor($user);
            AuditLogger::record([
                'module' => 'auth',
                'action' => 'token_use',
                'targetType' => 'api_token',
                'targetId' => (int) $row['id'],
                'targetLabel' => $row['name'],
                'details' => ['prefix' => $row['token_prefix']],
            ]);
        }

        return $user;
    }

    /** @param array<string, mixed> $row */
    public function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'prefix' => $row['token_prefix'],
            'expiresAt' => UtcTime::iso($row['expires_at'] ?? null),
            'lastUsedAt' => UtcTime::iso(isset($row['last_used_at']) ? (string) $row['last_used_at'] : null),
            'revokedAt' => UtcTime::iso(isset($row['revoked_at']) && $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null),
            'createdAt' => UtcTime::iso($row['created_at'] ?? null),
        ];
    }

    private function reject(string $reason, ?int $id = null): never
    {
        AuditLogger::record([
            'module' => 'auth',
            'action' => 'token_use',
            'result' => 'failed',
            'targetType' => $id === null ? null : 'api_token',
            'targetId' => $id,
            'details' => ['reason' => $reason],
        ]);
        throw HttpException::unauthenticated('This device token is not valid.', 'invalid_token');
    }

    /** @param array<string, string> $errors */
    private function name(mixed $raw, array &$errors): string
    {
        if (!is_string($raw)) {
            $errors['name'] = 'Enter a name for this device.';

            return '';
        }
        $name = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        if ($name === '' || mb_strlen($name) > 80 || preg_match('/[\p{Cc}\p{Cf}]/u', $name) === 1) {
            $errors['name'] = 'Enter a device name of 80 characters or fewer.';

            return '';
        }

        return $name;
    }

    /** @param array<string, mixed> $input @param array<string, string> $errors */
    private function days(array $input, array &$errors): int
    {
        if (!array_key_exists('expiresInDays', $input) || $input['expiresInDays'] === null || $input['expiresInDays'] === '') {
            return 90;
        }
        $raw = $input['expiresInDays'];
        if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
            $errors['expiresInDays'] = 'Enter a whole number of days.';

            return 90;
        }
        $days = (int) $raw;
        if ($days < 1 || $days > 365) {
            $errors['expiresInDays'] = 'Device tokens can last from 1 to 365 days.';

            return 90;
        }

        return $days;
    }
}
