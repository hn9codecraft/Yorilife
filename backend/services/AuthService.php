<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;
use App\Utils\HttpException;
use App\Utils\Session;

/**
 * Sign-in, sign-out and session validation.
 *
 * Session contents: user_id, authenticated_at, last_activity_at, csrf_token.
 * The user's role and status are re-read from the database on every request,
 * so disabling an account or changing a role takes effect immediately.
 */
final class AuthService
{
    private const INVALID_CREDENTIALS = 'The email or password is incorrect.';

    private readonly UserRepository $users;
    private readonly LoginAttemptRepository $attempts;
    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->attempts = new LoginAttemptRepository();
        $this->config = require dirname(__DIR__) . '/config/auth.php';
    }

    /**
     * @param array<string, mixed> $input { email, password }
     * @return array{user?: array<string, mixed>, csrfToken?: string, challenge?: array{token: string, purpose: string, expiresIn: int}}
     */
    public function login(array $input, string $ip): array
    {
        $errors = [];
        UserValidator::onlyFields($input, ['email', 'password'], $errors);
        $email = UserValidator::email($input['email'] ?? null, $errors);
        $password = $input['password'] ?? null;
        if (!is_string($password) || $password === '') {
            $errors['password'] = 'Enter your password.';
        } elseif (strlen($password) > 1024) {
            $errors['password'] = 'Password is too long.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        RequestContext::boot($ip);
        $identifierHash = hash('sha256', $email);
        try {
            $this->guardAgainstBruteForce($identifierHash, $ip);
        } catch (HttpException $e) {
            if ($e->errorCode === 'too_many_attempts') {
                AuditLogger::record([
                    'module' => 'auth',
                    'action' => 'login_throttled',
                    'result' => 'denied',
                    'actorId' => null,
                    'actorLabel' => null,
                    'details' => ['emailDomain' => self::emailDomain($email)],
                ]);
            }
            throw $e;
        }

        $account = $this->users->findForLogin($email);

        // Unknown emails are verified against a dummy hash, so response time
        // doesn't reveal whether an account exists.
        $valid = PasswordHasher::verify($password, $account['password_hash'] ?? null);

        if (!$valid) {
            $this->attempts->record($identifierHash, $ip, false);
            AuditLogger::record([
                'module' => 'auth',
                'action' => 'login',
                'result' => 'failed',
                'actorId' => null,
                'actorLabel' => null,
                'details' => ['reason' => 'invalid_credentials', 'emailDomain' => self::emailDomain($email)],
            ]);
            throw HttpException::unauthenticated(self::INVALID_CREDENTIALS, 'invalid_credentials');
        }

        // Only someone who knows the correct password learns the account is disabled or locked.
        if ($account['status'] !== 'active') {
            $locked = $account['status'] === 'locked';
            $this->attempts->record($identifierHash, $ip, false);
            AuditLogger::record([
                'module' => 'auth',
                'action' => 'login',
                'result' => 'denied',
                'actorId' => (int) $account['id'],
                'actorLabel' => $account['name'] ?? null,
                'targetType' => 'user',
                'targetId' => (int) $account['id'],
                'targetLabel' => $account['name'] ?? null,
                'details' => ['reason' => $locked ? 'account_locked' : 'account_disabled'],
            ]);
            throw HttpException::forbidden(
                $locked
                    ? 'This account is locked. Contact an administrator.'
                    : 'This account is disabled. Contact an administrator.',
                $locked ? 'account_locked' : 'account_disabled',
            );
        }

        $userId = (int) $account['id'];
        if (PasswordHasher::needsRehash($account['password_hash'])) {
            $this->users->updatePasswordHash($userId, PasswordHasher::hash($password));
        }

        $challenge = (new TwoFactorService())->challengeAfterPassword($account);
        if ($challenge !== null) {
            return ['challenge' => $challenge];
        }

        return $this->completeLogin($userId, $email, 'password', $ip);
    }

    /**
     * Second step after a correct password, when an authenticator code is required.
     *
     * @param array<string, mixed> $input
     * @return array{user: array<string, mixed>, csrfToken: string}
     */
    public function finishTwoFactor(array $input, string $ip): array
    {
        RequestContext::boot($ip);
        $verified = (new TwoFactorService())->verifyChallenge($input, $ip);
        $user = $this->users->find($verified['userId']) ?? throw HttpException::unauthenticated();

        return $this->completeLogin((int) $user['id'], (string) $user['email'], $verified['method'], $ip);
    }

    /**
     * First-time authenticator setup during sign-in, then a normal session.
     *
     * @param array<string, mixed> $input
     * @return array{user: array<string, mixed>, csrfToken: string, recoveryCodes: list<string>}
     */
    public function finishEnrollment(array $input, string $ip): array
    {
        RequestContext::boot($ip);
        $enrolled = (new TwoFactorService())->confirmEnrollment($input);
        $user = $this->users->find($enrolled['userId']) ?? throw HttpException::unauthenticated();
        $session = $this->completeLogin((int) $user['id'], (string) $user['email'], 'totp', $ip);
        $session['recoveryCodes'] = $enrolled['recoveryCodes'];

        return $session;
    }

    /** @return array{user: array<string, mixed>, csrfToken: string} */
    private function completeLogin(int $userId, string $email, string $method, string $ip): array
    {
        $identifierHash = hash('sha256', $email);
        $this->attempts->record($identifierHash, $ip, true);
        $this->attempts->clearFailures($identifierHash);
        $this->users->touchLastLogin($userId);

        $user = $this->users->find($userId) ?? throw HttpException::unauthenticated();
        Session::regenerate();
        $_SESSION = [
            'user_id' => $userId,
            'authenticated_at' => time(),
            'last_activity_at' => time(),
            'session_epoch' => (int) ($user['session_epoch'] ?? 0),
            'csrf_token' => bin2hex(random_bytes(32)),
        ];
        $csrf = $_SESSION['csrf_token'];
        Session::close();
        RequestContext::setActor($user);
        AuditLogger::record([
            'module' => 'auth',
            'action' => 'login',
            'result' => 'ok',
            'targetType' => 'user',
            'targetId' => $userId,
            'targetLabel' => $user['name'] ?? null,
            'details' => ['method' => $method],
        ]);
        if ($method === 'totp' || $method === 'recovery') {
            AuditLogger::record([
                'module' => 'auth',
                'action' => 'totp_used',
                'result' => 'ok',
                'targetType' => 'user',
                'targetId' => $userId,
                'targetLabel' => $user['name'] ?? null,
                'details' => ['method' => $method],
            ]);
        }

        return ['user' => $user, 'csrfToken' => $csrf];
    }

    public function logout(): void
    {
        AuditLogger::record([
            'module' => 'auth',
            'action' => 'logout',
            'result' => 'ok',
            'targetType' => 'user',
            'targetId' => RequestContext::actorId(),
            'targetLabel' => RequestContext::actorLabel(),
        ]);
        Session::destroy();
    }

    /**
     * Resolve the signed-in user from the session cookie, enforcing idle and
     * absolute expiry and the account's current status.
     *
     * @return array{user: array<string, mixed>, csrfToken: string}
     */
    public function currentSession(): array
    {
        if (!Session::hasCookie()) {
            throw HttpException::unauthenticated();
        }

        Session::start();
        $userId = $_SESSION['user_id'] ?? null;
        if (!is_int($userId)) {
            Session::destroy();
            throw HttpException::unauthenticated('Your session has ended. Please sign in again.', 'session_expired');
        }

        $now = time();
        $idleLimit = SecurityPolicy::sessionIdleMinutes() * 60;
        $absoluteLimit = SecurityPolicy::sessionAbsoluteHours() * 3600;
        if ($now - (int) ($_SESSION['last_activity_at'] ?? 0) > $idleLimit || $now - (int) ($_SESSION['authenticated_at'] ?? 0) > $absoluteLimit) {
            Session::destroy();
            throw HttpException::unauthenticated('Your session has expired. Please sign in again.', 'session_expired');
        }

        $user = $this->users->find($userId);
        if ($user === null || $user['status'] !== 'active') {
            Session::destroy();
            throw HttpException::unauthenticated('Your session has ended. Please sign in again.', 'session_expired');
        }
        if ((int) ($_SESSION['session_epoch'] ?? 0) !== (int) ($user['session_epoch'] ?? 0)) {
            Session::destroy();
            throw HttpException::unauthenticated('Your session has ended. Please sign in again.', 'session_expired');
        }
        if (SecurityPolicy::totpRequired((string) $user['role_slug']) && empty($user['totp_enabled_at'])) {
            Session::destroy();
            throw HttpException::unauthenticated(
                'This account must set up an authenticator app. Sign in again to continue.',
                'totp_enrollment_required',
            );
        }

        $_SESSION['last_activity_at'] = $now;
        $csrf = (string) $_SESSION['csrf_token'];
        Session::close();

        return ['user' => $user, 'csrfToken' => $csrf];
    }

    private static function emailDomain(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? 'unknown' : substr($email, $at + 1);
    }

    private function guardAgainstBruteForce(string $identifierHash, string $ip): void
    {
        $throttle = $this->config['throttle'];
        $window = SecurityPolicy::lockoutWindowMinutes();
        $byAccount = $this->attempts->recentFailures('identifier_hash', $identifierHash, $window);
        $byIp = $this->attempts->recentFailures('ip_address', $ip, $window);

        $limited = null;
        if ($byAccount['failures'] >= SecurityPolicy::lockoutMaxFailures()) {
            $limited = $byAccount['retry_after'];
        } elseif ($byIp['failures'] >= $throttle['max_failures_per_ip']) {
            $limited = $byIp['retry_after'];
        }

        if ($limited !== null) {
            throw new HttpException(
                429,
                'too_many_attempts',
                'Too many failed sign-in attempts. Please wait and try again.',
                ['retryAfterSeconds' => $limited],
                ['Retry-After' => (string) $limited],
            );
        }
    }
}
