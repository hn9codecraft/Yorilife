<?php

declare(strict_types=1);

namespace App\Repositories;

final class SettingsRepository extends Repository
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->many(
            'SELECT s.setting_key, s.setting_value, s.group_name, s.label, s.updated_at, s.updated_by, u.name AS updated_by_name
             FROM settings s
             LEFT JOIN users u ON u.id = s.updated_by
             ORDER BY s.group_name, s.label, s.setting_key'
        );
    }

    /** @return array<string, string> key => value */
    public function values(): array
    {
        $out = [];
        foreach ($this->many('SELECT setting_key, setting_value FROM settings') as $row) {
            $out[(string) $row['setting_key']] = $row['setting_value'] === null ? '' : (string) $row['setting_value'];
        }

        return $out;
    }

    public function updateValue(string $key, string $value, int $userId, string $updatedAt): void
    {
        $this->pdo()->prepare(
            'UPDATE settings SET setting_value = ?, updated_by = ?, updated_at = ? WHERE setting_key = ?'
        )->execute([$value, $userId, $updatedAt, $key]);
    }

    public function addVersion(string $key, ?string $old, ?string $new, int $userId, string $changedAt, ?string $requestId): int
    {
        $this->pdo()->prepare(
            'INSERT INTO setting_versions (setting_key, old_value, new_value, changed_by, changed_at, request_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$key, $old, $new, $userId, $changedAt, $requestId]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(?string $key, int $limit, int $offset): array
    {
        $where = '';
        $params = [];
        if ($key !== null) {
            $where = ' WHERE v.setting_key = ?';
            $params[] = $key;
        }
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        return $this->many(
            'SELECT v.id, v.setting_key, v.old_value, v.new_value, v.changed_at, v.changed_by, u.name AS changed_by_name
             FROM setting_versions v
             LEFT JOIN users u ON u.id = v.changed_by'
            . $where
            . ' ORDER BY v.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
    }

    public function historyCount(?string $key): int
    {
        if ($key === null) {
            return (int) ($this->one('SELECT COUNT(*) AS total FROM setting_versions')['total'] ?? 0);
        }
        $row = $this->one('SELECT COUNT(*) AS total FROM setting_versions WHERE setting_key = ?', [$key]);

        return (int) ($row['total'] ?? 0);
    }
}
