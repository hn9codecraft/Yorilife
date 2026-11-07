<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;

/**
 * System → Settings (Phase 1: read-only). Shows company settings from the
 * database plus the live login/session numbers from config/auth.php.
 */
final class SettingsService
{
    public function __construct(private readonly SettingsRepository $settings = new SettingsRepository())
    {
    }

    /** @param array<string, mixed> $actor */
    public function view(array $actor): array
    {
        Authorization::authorize($actor, 'settings.view');
        $auth = require dirname(__DIR__) . '/config/auth.php';
        $rows = $this->settings->all();
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['group_name']][] = [
                'key' => $row['setting_key'],
                'label' => $row['label'],
                'value' => $row['setting_value'],
                'updatedAt' => UtcTime::iso($row['updated_at'] ?? null),
                'updatedByName' => $row['updated_by_name'] ?? null,
                'editable' => false,
            ];
        }

        $groups['login_session'] = [
            [
                'key' => 'session.idle_timeout_minutes',
                'label' => 'Idle timeout (minutes)',
                'value' => (string) $auth['session']['idle_timeout_minutes'],
                'updatedAt' => null,
                'updatedByName' => null,
                'editable' => false,
                'source' => 'config/auth.php',
            ],
            [
                'key' => 'session.absolute_lifetime_hours',
                'label' => 'Absolute session lifetime (hours)',
                'value' => (string) $auth['session']['absolute_lifetime_hours'],
                'updatedAt' => null,
                'updatedByName' => null,
                'editable' => false,
                'source' => 'config/auth.php',
            ],
            [
                'key' => 'throttle.window_minutes',
                'label' => 'Login throttle window (minutes)',
                'value' => (string) $auth['throttle']['window_minutes'],
                'updatedAt' => null,
                'updatedByName' => null,
                'editable' => false,
                'source' => 'config/auth.php',
            ],
            [
                'key' => 'throttle.max_failures_per_account',
                'label' => 'Max failed logins per account',
                'value' => (string) $auth['throttle']['max_failures_per_account'],
                'updatedAt' => null,
                'updatedByName' => null,
                'editable' => false,
                'source' => 'config/auth.php',
            ],
            [
                'key' => 'throttle.max_failures_per_ip',
                'label' => 'Max failed logins per IP',
                'value' => (string) $auth['throttle']['max_failures_per_ip'],
                'updatedAt' => null,
                'updatedByName' => null,
                'editable' => false,
                'source' => 'config/auth.php',
            ],
            [
                'key' => 'password.min_length',
                'label' => 'Minimum password length',
                'value' => (string) $auth['password']['min_length'],
                'updatedAt' => null,
                'updatedByName' => null,
                'editable' => false,
                'source' => 'config/auth.php',
            ],
        ];

        return [
            'readOnly' => true,
            'groups' => [
                ['id' => 'company', 'label' => 'Company', 'items' => $groups['company'] ?? []],
                ['id' => 'login_session', 'label' => 'Login & session', 'items' => $groups['login_session']],
            ],
            'note' => 'Settings are view-only in Phase 1. Editing moves to Phase 2.',
        ];
    }
}
