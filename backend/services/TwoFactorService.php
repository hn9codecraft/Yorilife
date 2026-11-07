<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TwoFactorRepository;
use App\Repositories\UserRepository;
use App\Utils\HttpException;
use App\Utils\Session;

/**
 * Optional authenticator-app sign-in (TOTP) and one-time recovery codes.
 * A Super Admin can require it per role. Enabling, disabling and using a code
 * are written to the audit log. The secret and the codes are not.
 */
final class TwoFactorService
{
    private const RECOVERY_COUNT = 8;

    public function __construct(
        private readonly TwoFactorRepository $factors = new TwoFactorRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /** @param array<string, mixed> $actor */
    public function status(array $actor): array
    {
        $userId = (int) $actor['id'];
        $enabled = $this->factors->isEnabled($userId);

        return [
            'enabled' => $enabled,
            'required' => SecurityPolicy::totpRequiredFor((string) ($actor['role_slug'] ?? '')),
            'recoveryCodesRemaining' => $enabled ? $this->factors->recoveryRemaining($userId) : 0,
        ];
    }

    /**
     * Start setup. Returns the secret once. Does not turn 2FA on until confirm.
     *
     * @param array<string, mixed> $actor
     * @return array{secret: string, otpauthUri: string}
     */
    public function setup(array $actor): array
    {
        $userId = (int) $actor['id'];
        if ($this->factors->isEnabled($userId)) {
            throw new HttpException(409, 'totp_already_enabled', 'An authenticator app is already turned on for this account.');
        }
        $secret = Totp::generateSecret();
        $this->factors->savePending($userId, $secret, UtcTime::now());
        AuditLogger::record([
            'module' => 'auth',
            'action' => 'totp_setup',
            'targetType' => 'user',
            'targetId' => $userId,
            'targetLabel' => $actor['name'] ?? null,
        ]);

        return [
            'secret' => $secret,
            'otpauthUri' => Totp::uri($secret, (string) $actor['email']),
        ];
    }

    /**
     * Confirm a pending secret. Recovery codes are returned once.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     * @return array{enabled: bool, recoveryCodes: list<string>}
     */
    public function confirm(array $actor, array $input): array
    {
        $errors = [];
        UserValidator::onlyFields($input, ['code'], $errors);
        $code = $this->code($input['code'] ?? null, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $userId = (int) $actor['id'];
        if ($this->factors->isEnabled($userId)) {
            throw new HttpException(409, 'totp_already_enabled', 'An authenticator app is already turned on for this account.');
        }
        $secret = $this->factors->pendingSecret($userId);
        if ($secret === null) {
            throw HttpException::validation(['code' => 'Set up the authenticator app first.']);
        }
        $this->assertNotLocked();
        if (!Totp::verify($secret, $code)) {
            $this->recordFailure();
            AuditLogger::record([
                'module' => 'auth',
                'action' => 'totp_enable',
                'result' => 'failed',
                'targetType' => 'user',
                'targetId' => $userId,
                'targetLabel' => $actor['name'] ?? null,
            ]);
            throw HttpException::validation(['code' => 'That code is not correct.']);
        }

        $now = UtcTime::now();
        if (!$this->factors->enable($userId, $now)) {
            throw HttpException::validation(['code' => 'Set up the authenticator app first.']);
        }
        $plain = $this->freshRecoveryCodes();
        $this->factors->replaceRecoveryHashes($userId, array_map([Totp::class, 'hashRecovery'], $plain), $now);
        $this->markSessionPassed();
        AuditLogger::record([
            'module' => 'auth',
            'action' => 'totp_enable',
            'targetType' => 'user',
            'targetId' => $userId,
            'targetLabel' => $actor['name'] ?? null,
            'details' => ['recoveryCodes' => count($plain)],
        ]);

        return ['enabled' => true, 'recoveryCodes' => $plain];
    }

    /**
     * Finish sign-in after the password, using an authenticator code.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function verify(array $actor, array $input): array
    {
        return $this->acceptCode($actor, $input, false);
    }

    /**
     * Finish sign-in with a one-time recovery code.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function recover(array $actor, array $input): array
    {
        return $this->acceptCode($actor, $input, true);
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input { password, code }
     */
    public function disable(array $actor, array $input): array
    {
        $errors = [];
        UserValidator::onlyFields($input, ['password', 'code'], $errors);
        $password = $input['password'] ?? null;
        $code = is_string($input['code'] ?? null) ? trim($input['code']) : '';
        if (!is_string($password) || $password === '') {
            $errors['password'] = 'Enter your password.';
        }
        if ($code === '') {
            $errors['code'] = 'Enter a code from your authenticator app or a recovery code.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $userId = (int) $actor['id'];
        if (!$this->factors->isEnabled($userId)) {
            throw new HttpException(409, 'totp_not_enabled', 'An authenticator app is not turned on for this account.');
        }
        $account = $this->users->findForLogin((string) $actor['email']);
        if (!PasswordHasher::verify($password, $account['password_hash'] ?? null)) {
            throw HttpException::validation(['password' => 'The password is incorrect.']);
        }
        $this->assertNotLocked();
        if (!$this->proofMatches($userId, $code)) {
            $this->recordFailure();
            AuditLogger::record([
                'module' => 'auth',
                'action' => 'totp_disable',
                'result' => 'failed',
                'targetType' => 'user',
                'targetId' => $userId,
                'targetLabel' => $actor['name'] ?? null,
            ]);
            throw HttpException::validation(['code' => 'That code is not correct.']);
        }

        $this->factors->clear($userId);
        AccessRevoker::revoke($userId, true);
        $this->clearFailureCounter();
        AuditLogger::record([
            'module' => 'auth',
            'action' => 'totp_disable',
            'targetType' => 'user',
            'targetId' => $userId,
            'targetLabel' => $actor['name'] ?? null,
        ]);

        $required = SecurityPolicy::totpRequiredFor((string) ($actor['role_slug'] ?? ''));

        return ['enabled' => false, 'required' => $required, 'twoFactor' => $required ? 'enroll' : 'ok'];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input */
    private function acceptCode(array $actor, array $input, bool $recovery): array
    {
        $errors = [];
        UserValidator::onlyFields($input, ['code'], $errors);
        $raw = $input['code'] ?? null;
        $code = is_string($raw) ? trim($raw) : '';
        if ($code === '') {
            $errors['code'] = $recovery ? 'Enter a recovery code.' : 'Enter the 6-digit code.';
        } elseif (!$recovery && preg_match('/^\d{6}$/', $code) !== 1) {
            $errors['code'] = 'Enter the 6-digit code from your authenticator app.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $userId = (int) $actor['id'];
        $action = $recovery ? 'totp_recover' : 'totp_verify';
        if (!$this->factors->isEnabled($userId)) {
            throw new HttpException(409, 'totp_not_enabled', 'An authenticator app is not turned on for this account.');
        }
        $this->assertNotLocked();
        $ok = $recovery
            ? $this->factors->consumeRecoveryHash($userId, Totp::hashRecovery($code), UtcTime::now())
            : Totp::verify((string) $this->factors->enabledSecret($userId), $code);
        if (!$ok) {
            $this->recordFailure();
            AuditLogger::record([
                'module' => 'auth',
                'action' => $action,
                'result' => 'failed',
                'targetType' => 'user',
                'targetId' => $userId,
                'targetLabel' => $actor['name'] ?? null,
            ]);
            throw HttpException::unauthenticated(
                $recovery ? 'That recovery code is not correct or has already been used.' : 'That authenticator code is not correct.',
                $recovery ? 'invalid_recovery_code' : 'invalid_totp',
            );
        }

        $this->markSessionPassed();
        AuditLogger::record([
            'module' => 'auth',
            'action' => $action,
            'targetType' => 'user',
            'targetId' => $userId,
            'targetLabel' => $actor['name'] ?? null,
            'details' => $recovery ? ['recoveryCodesRemaining' => $this->factors->recoveryRemaining($userId)] : null,
        ]);

        return $this->users->find($userId) ?? throw HttpException::notFound('User not found.');
    }

    private function proofMatches(int $userId, string $code): bool
    {
        if (preg_match('/^\d{6}$/', $code) === 1) {
            $secret = $this->factors->enabledSecret($userId);

            return $secret !== null && Totp::verify($secret, $code);
        }

        return $this->factors->consumeRecoveryHash($userId, Totp::hashRecovery($code), UtcTime::now());
    }

    /** @return list<string> */
    private function freshRecoveryCodes(): array
    {
        $codes = [];
        while (count($codes) < self::RECOVERY_COUNT) {
            $code = Totp::recoveryCode();
            $codes[$code] = $code;
        }

        return array_values($codes);
    }

    /** @param array<string, string> $errors */
    private function code(mixed $raw, array &$errors): string
    {
        if (!is_string($raw) || preg_match('/^\d{6}$/', trim($raw)) !== 1) {
            $errors['code'] = 'Enter the 6-digit code from your authenticator app.';

            return '';
        }

        return trim($raw);
    }

    private function assertNotLocked(): void
    {
        Session::start();
        $until = (int) ($_SESSION['two_factor_locked_until'] ?? 0);
        Session::close();
        if ($until > time()) {
            $retry = max(1, $until - time());
            throw new HttpException(
                429,
                'too_many_attempts',
                'Too many incorrect codes. Please wait and try again.',
                ['retryAfterSeconds' => $retry],
                ['Retry-After' => (string) $retry],
            );
        }
    }

    private function recordFailure(): void
    {
        Session::start();
        $failures = (int) ($_SESSION['two_factor_failures'] ?? 0) + 1;
        if ($failures >= 5) {
            $_SESSION['two_factor_failures'] = 0;
            $_SESSION['two_factor_locked_until'] = time() + (SecurityPolicy::lockoutWindowMinutes() * 60);
        } else {
            $_SESSION['two_factor_failures'] = $failures;
        }
        Session::close();
    }

    private function clearFailureCounter(): void
    {
        Session::start();
        $_SESSION['two_factor_failures'] = 0;
        unset($_SESSION['two_factor_locked_until']);
        Session::close();
    }

    private function markSessionPassed(): void
    {
        Session::start();
        $_SESSION['two_factor'] = 'ok';
        $_SESSION['two_factor_failures'] = 0;
        unset($_SESSION['two_factor_locked_until']);
        Session::close();
    }
}
