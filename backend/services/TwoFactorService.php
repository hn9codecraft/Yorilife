<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TwoFactorRepository;
use App\Repositories\UserRepository;
use App\Utils\HttpException;

/**
 * Optional authenticator-app sign-in (TOTP) and one-time recovery codes.
 * A Super Admin can require it per role. Enable, disable and use are audited.
 * Secrets and codes are never written to the audit log.
 */
final class TwoFactorService
{
    private const CHALLENGE_TTL = 600;

    public function __construct(
        private readonly TwoFactorRepository $factors = new TwoFactorRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /** @param array<string, mixed> $actor */
    public function status(array $actor): array
    {
        $user = $this->factors->findUser((int) $actor['id']) ?? throw HttpException::unauthenticated();

        return [
            'totpEnabled' => $user['totp_enabled_at'] !== null,
            'totpRequired' => SecurityPolicy::totpRequired((string) $user['role_slug']),
            'pendingSetup' => is_string($user['totp_pending_secret']) && $user['totp_pending_secret'] !== '' && $user['totp_enabled_at'] === null,
            'recoveryCodesRemaining' => $this->factors->unusedRecoveryCount((int) $user['id']),
        ];
    }

    /**
     * Start setup for the signed-in user. Returns the secret once.
     *
     * @param array<string, mixed> $actor
     * @return array{secret: string, otpauthUri: string, issuer: string, account: string}
     */
    public function beginSetup(array $actor): array
    {
        $user = $this->factors->findUser((int) $actor['id']) ?? throw HttpException::unauthenticated();
        if ($user['totp_enabled_at'] !== null) {
            throw new HttpException(409, 'totp_already_enabled', 'An authenticator app is already turned on for this account.');
        }
        $secret = Totp::generateSecret();
        $this->factors->savePending((int) $user['id'], $secret);

        return $this->setupPayload($secret, (string) $user['email']);
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     * @return array{enabled: true, recoveryCodes: list<string>}
     */
    public function confirmSetup(array $actor, array $input): array
    {
        $errors = [];
        UserValidator::onlyFields($input, ['code'], $errors);
        $code = $this->codeField($input['code'] ?? null, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $user = $this->factors->findUser((int) $actor['id']) ?? throw HttpException::unauthenticated();
        if ($user['totp_enabled_at'] !== null) {
            throw new HttpException(409, 'totp_already_enabled', 'An authenticator app is already turned on for this account.');
        }
        $pending = is_string($user['totp_pending_secret']) ? $user['totp_pending_secret'] : '';
        $step = $pending === '' ? null : Totp::matchStep($pending, $code);
        if ($step === null) {
            throw HttpException::validation(['code' => 'That authenticator code is not valid. Check the clock and try again.']);
        }

        return $this->enable((int) $user['id'], $pending, $step, (string) $user['name']);
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input { password, code }
     */
    public function disable(array $actor, array $input): array
    {
        $user = $this->requireEnabledActor($actor, $input, true);
        $this->factors->clear((int) $user['id']);
        $this->factors->retireRecoveryCodes((int) $user['id']);
        (new \App\Repositories\ApiTokenRepository())->revokeActive((int) $user['id']);
        $this->audit('totp_disabled', (int) $user['id'], (string) $user['name'], ['via' => 'self']);

        return $this->status($actor);
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input { password, code }
     * @return array{recoveryCodes: list<string>}
     */
    public function regenerateRecoveryCodes(array $actor, array $input): array
    {
        $user = $this->requireEnabledActor($actor, $input, true);
        $codes = $this->replaceRecoveryCodes((int) $user['id']);
        $this->audit('totp_recovery_regenerated', (int) $user['id'], (string) $user['name'], ['count' => count($codes)]);

        return ['recoveryCodes' => $codes];
    }

    /** @param array<string, mixed> $actor */
    public function resetForUser(array $actor, int $targetId): void
    {
        $target = $this->factors->findUser($targetId) ?? throw HttpException::notFound('User not found.');
        $this->factors->clear($targetId);
        $this->factors->retireRecoveryCodes($targetId);
        $revoked = CredentialRevoker::revoke($targetId);
        $this->audit('totp_disabled', $targetId, (string) $target['name'], [
            'via' => 'admin',
            'sessionsRevoked' => true,
            'devicesRevoked' => $revoked['devicesRevoked'],
        ]);
    }

    /**
     * Password was accepted. Returns a challenge when a second step is required.
     *
     * @param array<string, mixed> $account login row including role_slug and totp columns
     * @return array{token: string, purpose: string, expiresIn: int}|null
     */
    public function challengeAfterPassword(array $account): ?array
    {
        $enabled = $account['totp_enabled_at'] !== null && is_string($account['totp_secret']) && $account['totp_secret'] !== '';
        $required = SecurityPolicy::totpRequired((string) ($account['role_slug'] ?? ''));
        if (!$enabled && !$required) {
            return null;
        }
        $purpose = $enabled ? 'totp' : 'enroll';
        $token = $this->factors->issueChallenge((int) $account['id'], $purpose, self::CHALLENGE_TTL);
        AuditLogger::record([
            'module' => 'auth',
            'action' => 'login_password',
            'result' => 'ok',
            'actorId' => (int) $account['id'],
            'actorLabel' => $account['name'] ?? null,
            'targetType' => 'user',
            'targetId' => (int) $account['id'],
            'targetLabel' => $account['name'] ?? null,
            'details' => ['next' => $purpose],
        ]);

        return ['token' => $token, 'purpose' => $purpose, 'expiresIn' => self::CHALLENGE_TTL];
    }

    /**
     * Finish sign-in with an authenticator or recovery code.
     *
     * @param array<string, mixed> $input { challengeToken, code }
     * @return array{userId: int, method: string}
     */
    public function verifyChallenge(array $input, string $ip): array
    {
        $errors = [];
        UserValidator::onlyFields($input, ['challengeToken', 'code'], $errors);
        $token = $input['challengeToken'] ?? null;
        if (!is_string($token) || $token === '') {
            $errors['challengeToken'] = 'Sign in again. This step has expired.';
        }
        $code = $input['code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            $errors['code'] = 'Enter the code from your authenticator app, or a recovery code.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $challenge = $this->openChallenge($token, 'totp');
        $user = $this->factors->findUser((int) $challenge['user_id']) ?? throw HttpException::unauthenticated();
        if ($user['status'] !== 'active') {
            throw HttpException::forbidden(
                $user['status'] === 'locked'
                    ? 'This account is locked. Contact an administrator.'
                    : 'This account is disabled. Contact an administrator.',
                $user['status'] === 'locked' ? 'account_locked' : 'account_disabled',
            );
        }
        $this->guardFailures($user, $ip);
        $method = $this->acceptCode($user, trim($code));
        if ($method === null) {
            $this->recordFailure($user, $ip, 'totp');
            throw HttpException::unauthenticated('That code is not valid.', 'totp_invalid');
        }
        $this->factors->consumeChallenge((int) $challenge['id']);
        $this->clearFailures($user);

        return ['userId' => (int) $user['id'], 'method' => $method];
    }

    /**
     * @param array<string, mixed> $input { challengeToken }
     * @return array{secret: string, otpauthUri: string, issuer: string, account: string}
     */
    public function beginEnrollment(array $input): array
    {
        $errors = [];
        UserValidator::onlyFields($input, ['challengeToken'], $errors);
        $token = $input['challengeToken'] ?? null;
        if (!is_string($token) || $token === '') {
            $errors['challengeToken'] = 'Sign in again to set up the authenticator app.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $challenge = $this->openChallenge($token, 'enroll');
        $user = $this->factors->findUser((int) $challenge['user_id']) ?? throw HttpException::unauthenticated();
        if ($user['status'] !== 'active') {
            throw HttpException::forbidden('This account cannot sign in.', 'account_disabled');
        }
        if ($user['totp_enabled_at'] !== null) {
            throw new HttpException(409, 'totp_already_enabled', 'An authenticator app is already turned on. Enter its code to sign in.');
        }
        $secret = Totp::generateSecret();
        $this->factors->savePending((int) $user['id'], $secret);

        return $this->setupPayload($secret, (string) $user['email']);
    }

    /**
     * @param array<string, mixed> $input { challengeToken, code }
     * @return array{userId: int, recoveryCodes: list<string>}
     */
    public function confirmEnrollment(array $input): array
    {
        $errors = [];
        UserValidator::onlyFields($input, ['challengeToken', 'code'], $errors);
        $token = $input['challengeToken'] ?? null;
        if (!is_string($token) || $token === '') {
            $errors['challengeToken'] = 'Sign in again to set up the authenticator app.';
        }
        $code = $this->codeField($input['code'] ?? null, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $challenge = $this->openChallenge($token, 'enroll');
        $user = $this->factors->findUser((int) $challenge['user_id']) ?? throw HttpException::unauthenticated();
        $pending = is_string($user['totp_pending_secret']) ? $user['totp_pending_secret'] : '';
        $step = $pending === '' ? null : Totp::matchStep($pending, $code);
        if ($step === null) {
            throw HttpException::validation(['code' => 'That authenticator code is not valid. Check the clock and try again.']);
        }
        RequestContext::setActor(['id' => (int) $user['id'], 'name' => $user['name']]);
        $enabled = $this->enable((int) $user['id'], $pending, $step, (string) $user['name']);
        $this->factors->consumeChallenge((int) $challenge['id']);

        return ['userId' => (int) $user['id'], 'recoveryCodes' => $enabled['recoveryCodes']];
    }

    /** @param array<string, string> $errors */
    private function codeField(mixed $value, array &$errors): string
    {
        if (!is_string($value) || preg_match('/^\d{6}$/', trim($value)) !== 1) {
            $errors['code'] = 'Enter the 6-digit code from your authenticator app.';

            return '';
        }

        return trim($value);
    }

    /** @return array{secret: string, otpauthUri: string, issuer: string, account: string} */
    private function setupPayload(string $secret, string $email): array
    {
        return [
            'secret' => $secret,
            'otpauthUri' => Totp::otpauthUri($secret, $email),
            'issuer' => 'Yorilife',
            'account' => $email,
        ];
    }

    /** @return array{enabled: true, recoveryCodes: list<string>} */
    private function enable(int $userId, string $secret, int $step, string $name): array
    {
        $this->factors->enable($userId, $secret, $step);
        $codes = $this->replaceRecoveryCodes($userId);
        $this->audit('totp_enabled', $userId, $name, ['recoveryCodes' => count($codes)]);

        return ['enabled' => true, 'recoveryCodes' => $codes];
    }

    /** @return list<string> */
    private function replaceRecoveryCodes(int $userId): array
    {
        $this->factors->retireRecoveryCodes($userId);
        $codes = RecoveryCodes::generate();
        $this->factors->insertRecoveryCodes($userId, array_map([RecoveryCodes::class, 'hash'], $codes));

        return $codes;
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function requireEnabledActor(array $actor, array $input, bool $requirePassword): array
    {
        $errors = [];
        $allowed = $requirePassword ? ['password', 'code'] : ['code'];
        UserValidator::onlyFields($input, $allowed, $errors);
        $user = $this->factors->findUser((int) $actor['id']) ?? throw HttpException::unauthenticated();
        if ($user['totp_enabled_at'] === null || !is_string($user['totp_secret']) || $user['totp_secret'] === '') {
            throw new HttpException(409, 'totp_not_enabled', 'An authenticator app is not turned on for this account.');
        }
        if ($requirePassword) {
            $password = $input['password'] ?? null;
            if (!is_string($password) || $password === '') {
                $errors['password'] = 'Enter your password.';
            } elseif (!PasswordHasher::verify($password, (string) $user['password_hash'])) {
                $errors['password'] = 'The password is incorrect.';
            }
        }
        $code = $input['code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            $errors['code'] = 'Enter the code from your authenticator app, or a recovery code.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($this->acceptCode($user, trim((string) $code)) === null) {
            throw HttpException::validation(['code' => 'That code is not valid.']);
        }

        return $user;
    }

    /** @param array<string, mixed> $user @return 'totp'|'recovery'|null */
    private function acceptCode(array $user, string $code): ?string
    {
        $secret = is_string($user['totp_secret']) ? $user['totp_secret'] : '';
        if ($secret !== '' && preg_match('/^\d{6}$/', $code) === 1) {
            $step = Totp::matchStep($secret, $code);
            $last = $user['totp_last_step'] === null ? null : (int) $user['totp_last_step'];
            if ($step !== null && ($last === null || $step > $last)) {
                $this->factors->setLastStep((int) $user['id'], $step);

                return 'totp';
            }
        }
        if (RecoveryCodes::looksLike($code) && $this->factors->consumeRecoveryCode((int) $user['id'], RecoveryCodes::hash($code))) {
            return 'recovery';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function openChallenge(string $token, string $purpose): array
    {
        $row = $this->factors->findChallenge($token);
        if ($row === null || $row['used_at'] !== null || (string) $row['purpose'] !== $purpose) {
            throw HttpException::unauthenticated('Sign in again. This step is no longer valid.', 'challenge_invalid');
        }
        if (strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            throw HttpException::unauthenticated('Sign in again. This step has expired.', 'challenge_expired');
        }

        return $row;
    }

    /** @param array<string, mixed> $user */
    private function guardFailures(array $user, string $ip): void
    {
        $hash = hash('sha256', (string) $user['email']);
        $window = SecurityPolicy::lockoutWindowMinutes();
        $attempts = new \App\Repositories\LoginAttemptRepository();
        $byAccount = $attempts->recentFailures('identifier_hash', $hash, $window);
        if ($byAccount['failures'] >= SecurityPolicy::lockoutMaxFailures()) {
            throw new HttpException(
                429,
                'too_many_attempts',
                'Too many failed sign-in attempts. Please wait and try again.',
                ['retryAfterSeconds' => $byAccount['retry_after']],
                ['Retry-After' => (string) $byAccount['retry_after']],
            );
        }
        unset($ip);
    }

    /** @param array<string, mixed> $user */
    private function recordFailure(array $user, string $ip, string $method): void
    {
        (new \App\Repositories\LoginAttemptRepository())->record(hash('sha256', (string) $user['email']), $ip, false);
        AuditLogger::record([
            'module' => 'auth',
            'action' => 'totp_used',
            'result' => 'failed',
            'actorId' => (int) $user['id'],
            'actorLabel' => $user['name'] ?? null,
            'targetType' => 'user',
            'targetId' => (int) $user['id'],
            'targetLabel' => $user['name'] ?? null,
            'details' => ['method' => $method],
        ]);
    }

    /** @param array<string, mixed> $user */
    private function clearFailures(array $user): void
    {
        (new \App\Repositories\LoginAttemptRepository())->clearFailures(hash('sha256', (string) $user['email']));
    }

    /** @param array<string, mixed> $details */
    private function audit(string $action, int $userId, string $name, array $details): void
    {
        AuditLogger::record([
            'module' => 'auth',
            'action' => $action,
            'result' => 'ok',
            'targetType' => 'user',
            'targetId' => $userId,
            'targetLabel' => $name,
            'details' => $details,
        ]);
    }
}
