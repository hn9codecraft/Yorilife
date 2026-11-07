<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ApiTokenRepository;
use App\Repositories\UserRepository;
use App\Utils\HttpException;

/**
 * Personal device keys for a future mobile app. The plaintext key is returned
 * once, at creation. Later requests send it as Authorization: Bearer.
 * Web sessions keep using the cookie. A bearer request does not need a CSRF token.
 */
final class ApiTokenService
{
    private const MAX_ACTIVE = 10;

    public function __construct(
        private readonly ApiTokenRepository $tokens = new ApiTokenRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /** @param array<string, mixed> $actor */
    public function list(array $actor): array
    {
        $rows = $this->tokens->listForUser((int) $actor['id']);

        return ['devices' => array_map([$this, 'present'], $rows)];
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input { name, expiresInDays? }
     * @return array{device: array<string, mixed>, key: string}
     */
    public function create(array $actor, array $input, ?string $ip): array
    {
        $errors = [];
        UserValidator::onlyFields($input, ['name', 'expiresInDays'], $errors);
        $name = $this->name($input['name'] ?? null, $errors);
        $days = $this->days($input, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $userId = (int) $actor['id'];
        if ($this->tokens->activeCount($userId) >= self::MAX_ACTIVE) {
            throw HttpException::validation(['name' => 'Revoke an old device before adding another. Ten active devices is the limit.']);
        }
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($days * 86400));
        $created = $this->tokens->create($userId, $name, $expiresAt, $ip);
        $row = $this->tokens->findForUser($userId, $created['id']) ?? throw new \RuntimeException('Device key missing after insert.');
        AuditLogger::record([
            'module' => 'auth',
            'action' => 'api_token_created',
            'targetType' => 'user',
            'targetId' => $userId,
            'targetLabel' => (string) ($actor['name'] ?? ''),
            'details' => ['deviceId' => $created['id'], 'name' => $name, 'prefix' => $created['prefix'], 'expiresInDays' => $days],
        ]);

        return ['device' => $this->present($row), 'key' => $created['plain']];
    }

    /** @param array<string, mixed> $actor */
    public function revoke(array $actor, int $id): array
    {
        $userId = (int) $actor['id'];
        $row = $this->tokens->findForUser($userId, $id) ?? throw HttpException::notFound('Device not found.');
        if ($row['revoked_at'] === null) {
            $this->tokens->revoke($userId, $id);
            AuditLogger::record([
                'module' => 'auth',
                'action' => 'api_token_revoked',
                'targetType' => 'user',
                'targetId' => $userId,
                'targetLabel' => (string) ($actor['name'] ?? ''),
                'details' => ['deviceId' => $id, 'name' => $row['name'], 'prefix' => $row['token_prefix']],
            ]);
        }
        $fresh = $this->tokens->findForUser($userId, $id) ?? throw HttpException::notFound('Device not found.');

        return ['device' => $this->present($fresh)];
    }

    /** @return array<string, mixed> signed-in user from UserRepository::find() */
    public function authenticate(string $plain): array
    {
        if (preg_match('/^ylt_[a-f0-9]{64}$/', $plain) !== 1) {
            throw HttpException::unauthenticated('This sign-in key is not valid.', 'invalid_token');
        }
        $row = $this->tokens->findByHash(hash('sha256', $plain));
        if ($row === null || $row['revoked_at'] !== null) {
            throw HttpException::unauthenticated('This sign-in key is not valid.', 'invalid_token');
        }
        if (strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            throw HttpException::unauthenticated('This sign-in key has expired.', 'token_expired');
        }
        $user = $this->users->find((int) $row['user_id']);
        if ($user === null || $user['status'] !== 'active') {
            throw HttpException::unauthenticated('Your session has ended. Please sign in again.', 'session_expired');
        }
        if (SecurityPolicy::totpRequired((string) $user['role_slug']) && empty($user['totp_enabled_at'])) {
            throw HttpException::unauthenticated(
                'This account must set up an authenticator app. Sign in on the web to continue.',
                'totp_enrollment_required',
            );
        }
        $this->tokens->touchLastUsed((int) $row['id']);

        return $user;
    }

    /** @param array<string, mixed> $row */
    public function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'prefix' => (string) $row['token_prefix'],
            'createdAt' => UtcTime::iso($row['created_at'] ?? null),
            'expiresAt' => UtcTime::iso($row['expires_at'] ?? null),
            'lastUsedAt' => UtcTime::iso(isset($row['last_used_at']) ? (string) $row['last_used_at'] : null),
            'revokedAt' => UtcTime::iso(isset($row['revoked_at']) && $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null),
        ];
    }

    /** @param array<string, string> $errors */
    private function name(mixed $value, array &$errors): string
    {
        $name = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
        if ($name === '') {
            $errors['name'] = 'Name this device so you can tell it apart later.';
        } elseif (mb_strlen($name) > 80 || preg_match('/[\p{Cc}\p{Cf}]/u', $name) === 1) {
            $errors['name'] = 'Use a device name of 80 characters or fewer.';
        }

        return $name;
    }

    /** @param array<string, mixed> $input @param array<string, string> $errors */
    private function days(array $input, array &$errors): int
    {
        if (!array_key_exists('expiresInDays', $input) || $input['expiresInDays'] === null || $input['expiresInDays'] === '') {
            return SecurityPolicy::apiTokenDefaultDays();
        }
        $raw = $input['expiresInDays'];
        if (!is_int($raw) && !(is_string($raw) && preg_match('/^\d+$/', $raw) === 1)) {
            $errors['expiresInDays'] = 'Enter a whole number of days.';

            return SecurityPolicy::apiTokenDefaultDays();
        }
        $days = (int) $raw;
        if ($days < 1 || $days > 365) {
            $errors['expiresInDays'] = 'A device key can last from 1 to 365 days.';
        }

        return $days;
    }
}
