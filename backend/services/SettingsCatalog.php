<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Editable settings: group, input type, default and validation.
 * Company and general settings use settings.edit (Admin and Super Admin).
 * Security settings are Super Admin only. Retention uses settings.edit.
 * Values in the database override these defaults.
 */
final class SettingsCatalog
{
    /** @var array<string, string> */
    public const GROUPS = [
        'company' => 'Company',
        'general' => 'General',
        'security' => 'Security',
        'retention' => 'Retention',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            'company.name' => self::text('company', 'Company name', 'Yorilife', true, 150, 'Shown as the organisation name.'),
            'company.address' => self::text('company', 'Company address', '', false, 500, 'Postal address. Line breaks are kept.', 'textarea'),
            'company.gstin' => [
                'group' => 'company', 'label' => 'GSTIN', 'input' => 'text', 'default' => '',
                'help' => '15-character GSTIN, or leave blank.',
            ],
            'company.logo_path' => [
                'group' => 'company', 'label' => 'Logo path', 'input' => 'text', 'default' => '',
                'help' => 'Optional relative path. The logo file itself is not changed here.',
            ],
            'general.support_email' => [
                'group' => 'general', 'label' => 'Support email', 'input' => 'email', 'default' => '',
                'help' => 'Contact address for staff. Leave blank if you do not want one stored.',
            ],
            'general.records_per_page' => [
                'group' => 'general', 'label' => 'Default list size', 'input' => 'number', 'default' => '50',
                'help' => '25, 50 or 100. Used when a list does not ask for a page size.',
            ],
            'security.password_min_length' => self::number('security', 'Minimum password length', '12', 8, 64, 'Between 8 and 64 characters. Passwords longer than 72 bytes are still rejected.'),
            'security.password_require_uppercase' => self::bool('security', 'Password requires an uppercase letter', '0'),
            'security.password_require_lowercase' => self::bool('security', 'Password requires a lowercase letter', '0'),
            'security.password_require_number' => self::bool('security', 'Password requires a number', '0'),
            'security.password_require_symbol' => self::bool('security', 'Password requires a symbol', '0'),
            'security.session_idle_minutes' => self::number('security', 'Idle timeout (minutes)', '30', 5, 720, 'Sign-in ends after this many minutes without a request.'),
            'security.session_absolute_hours' => self::number('security', 'Absolute session lifetime (hours)', '12', 1, 168, 'Sign-in ends after this many hours even if the user is active.'),
            'security.lockout_max_failures' => self::number('security', 'Failed attempts before lockout', '5', 3, 20, 'Wrong passwords for one account inside the lockout window.'),
            'security.lockout_window_minutes' => self::number('security', 'Lockout window (minutes)', '15', 1, 1440, 'How long failed attempts are counted, and how long the lockout lasts.'),
            'security.totp_required_super_admin' => self::bool('security', 'Require an authenticator app for Super Admin', '0', 'When yes, a Super Admin must finish authenticator setup before using the ERP.'),
            'security.totp_required_admin' => self::bool('security', 'Require an authenticator app for Admin', '0', 'When yes, an Admin must finish authenticator setup before using the ERP.'),
            'security.totp_required_sales_manager' => self::bool('security', 'Require an authenticator app for Sales Manager', '0', 'When yes, a Sales Manager must finish authenticator setup before using the ERP.'),
            'security.totp_required_sales_user' => self::bool('security', 'Require an authenticator app for Sales User', '0', 'When yes, a Sales User must finish authenticator setup before using the ERP.'),
            'retention.audit_log_days' => self::number('retention', 'Archive audit logs older than (days)', '365', 30, 3650, 'Archive marks rows. It never deletes them.'),
            'retention.change_history_days' => self::number('retention', 'Archive change history older than (days)', '365', 30, 3650, 'Archive marks rows. It never deletes them.'),
            'retention.import_audit_days' => self::number('retention', 'Archive import audit logs older than (days)', '365', 30, 3650, 'Archive marks rows. It never deletes them.'),
            'retention.login_attempt_days' => self::number('retention', 'Archive sign-in attempts older than (days)', '365', 30, 3650, 'Archive marks rows. It never deletes them.'),
            'retention.privacy_record_days' => self::number('retention', 'Archive privacy records older than (days)', '365', 30, 3650, 'Consent and privacy-request rows are marked, never deleted.'),
        ];
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::definitions() as $key => $definition) {
            $out[$key] = (string) $definition['default'];
        }

        return $out;
    }

    /** @param array<string, mixed> $actor */
    public static function canEditGroup(string $group, array $actor): bool
    {
        if (($actor['status'] ?? '') !== 'active') {
            return false;
        }
        if ($group === 'security') {
            return ($actor['role_slug'] ?? '') === 'super_admin';
        }

        return Authorization::can($actor, 'settings.edit');
    }

    /**
     * @param array<string, string> $current other stored values, used when two settings must agree
     * @return array{value: string, error: string|null}
     */
    public static function normalise(string $key, mixed $raw, array $current = []): array
    {
        $definition = self::definitions()[$key] ?? null;
        if ($definition === null) {
            return ['value' => '', 'error' => 'Unknown setting.'];
        }
        $input = $definition['input'];
        if ($input === 'bool') {
            $parsed = self::parseBool($raw);
            if ($parsed === null) {
                return ['value' => '', 'error' => 'Choose yes or no.'];
            }

            return ['value' => $parsed, 'error' => null];
        }
        if ($input === 'number') {
            if (is_int($raw) || (is_string($raw) && preg_match('/^-?\d+$/', trim($raw)) === 1)) {
                $number = (int) $raw;
            } else {
                return ['value' => '', 'error' => 'Enter a whole number.'];
            }
            $min = (int) $definition['min'];
            $max = (int) $definition['max'];
            if ($number < $min || $number > $max) {
                return ['value' => '', 'error' => "Enter a number from {$min} to {$max}."];
            }
            if ($key === 'security.session_idle_minutes' || $key === 'security.session_absolute_hours') {
                $idle = $key === 'security.session_idle_minutes' ? $number : (int) ($current['security.session_idle_minutes'] ?? 30);
                $hours = $key === 'security.session_absolute_hours' ? $number : (int) ($current['security.session_absolute_hours'] ?? 12);
                if ($idle > $hours * 60) {
                    return ['value' => '', 'error' => 'Idle timeout must fit inside the absolute session lifetime.'];
                }
            }
            if ($key === 'general.records_per_page' && !in_array($number, [25, 50, 100], true)) {
                return ['value' => '', 'error' => 'Choose 25, 50 or 100.'];
            }

            return ['value' => (string) $number, 'error' => null];
        }

        if (is_bool($raw) || is_array($raw) || is_object($raw)) {
            return ['value' => '', 'error' => 'Enter text.'];
        }
        $text = trim((string) ($raw ?? ''));
        if ($key === 'company.name') {
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            if ($text === '') {
                return ['value' => '', 'error' => 'Enter a company name.'];
            }
            if (mb_strlen($text) > 150 || preg_match('/[\p{Cc}\p{Cf}]/u', $text) === 1) {
                return ['value' => '', 'error' => 'Company name must be 150 characters or fewer and use ordinary characters.'];
            }

            return ['value' => $text, 'error' => null];
        }
        if ($key === 'company.address') {
            $text = trim(str_replace("\r\n", "\n", (string) ($raw ?? '')));
            if (mb_strlen($text) > 500) {
                return ['value' => '', 'error' => 'Address must be 500 characters or fewer.'];
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) === 1) {
                return ['value' => '', 'error' => 'Address contains invalid characters.'];
            }

            return ['value' => $text, 'error' => null];
        }
        if ($key === 'company.gstin') {
            if ($text === '') {
                return ['value' => '', 'error' => null];
            }
            $text = strtoupper($text);
            if (preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $text) !== 1) {
                return ['value' => '', 'error' => 'Enter a valid 15-character GSTIN, or leave it blank.'];
            }

            return ['value' => $text, 'error' => null];
        }
        if ($key === 'company.logo_path') {
            if ($text === '') {
                return ['value' => '', 'error' => null];
            }
            if (
                strlen($text) > 255
                || str_contains($text, '..')
                || str_contains($text, '\\')
                || str_starts_with($text, '/')
                || preg_match('#^[a-z][a-z0-9+.-]*://#i', $text) === 1
                || preg_match('#^[A-Za-z0-9._/-]+$#', $text) !== 1
            ) {
                return ['value' => '', 'error' => 'Enter a relative path such as assets/logo.png, with no web address.'];
            }

            return ['value' => $text, 'error' => null];
        }
        if ($key === 'general.support_email') {
            if ($text === '') {
                return ['value' => '', 'error' => null];
            }
            $errors = [];
            $email = UserValidator::email($text, $errors);
            if ($email === null) {
                return ['value' => '', 'error' => $errors['email'] ?? 'Enter a valid email address.'];
            }

            return ['value' => $email, 'error' => null];
        }

        return ['value' => $text, 'error' => 'This setting cannot be changed here.'];
    }

    /** @param array<string, mixed> $definition */
    private static function text(string $group, string $label, string $default, bool $required, int $max, string $help, string $input = 'text'): array
    {
        return [
            'group' => $group,
            'label' => $label,
            'input' => $input,
            'default' => $default,
            'required' => $required,
            'max' => $max,
            'help' => $help,
        ];
    }

    /** @return array<string, mixed> */
    private static function number(string $group, string $label, string $default, int $min, int $max, string $help): array
    {
        return [
            'group' => $group,
            'label' => $label,
            'input' => 'number',
            'default' => $default,
            'min' => $min,
            'max' => $max,
            'help' => $help,
        ];
    }

    /** @return array<string, mixed> */
    private static function bool(string $group, string $label, string $default, ?string $help = null): array
    {
        return [
            'group' => $group,
            'label' => $label,
            'input' => 'bool',
            'default' => $default,
            'help' => $help ?? 'Applies the next time a password is set. Existing passwords stay valid until they are changed.',
        ];
    }

    private static function parseBool(mixed $raw): ?string
    {
        if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') {
            return '1';
        }
        if ($raw === false || $raw === 0 || $raw === '0' || $raw === 'false') {
            return '0';
        }

        return null;
    }
}
