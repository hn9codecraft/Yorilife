<?php

declare(strict_types=1);

namespace App\Repositories;

final class SettingsRepository extends Repository
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->many(
            'SELECT s.setting_key, s.setting_value, s.group_name, s.label, s.updated_at, u.name AS updated_by_name
             FROM settings s
             LEFT JOIN users u ON u.id = s.updated_by
             ORDER BY s.group_name, s.label, s.setting_key'
        );
    }
}
