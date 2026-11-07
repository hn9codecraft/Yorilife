<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use App\Utils\HttpException;

/**
 * System → Settings. Company, general and retention settings are editable by
 * Admin and Super Admin (settings.edit). Security settings are editable by
 * Super Admin only. Every change keeps the previous value.
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
        $stored = [];
        foreach ($this->settings->all() as $row) {
            $stored[$row['setting_key']] = $row;
        }

        $grouped = [];
        foreach (SettingsCatalog::definitions() as $key => $definition) {
            $row = $stored[$key] ?? null;
            $group = (string) $definition['group'];
            $grouped[$group][] = [
                'key' => $key,
                'label' => $row['label'] ?? $definition['label'],
                'value' => $row['setting_value'] ?? $definition['default'],
                'input' => $definition['input'],
                'help' => $definition['help'] ?? null,
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
                'updatedAt' => UtcTime::iso($row['updated_at'] ?? null),
                'updatedByName' => $row['updated_by_name'] ?? null,
                'editable' => SettingsCatalog::canEditGroup($group, $actor),
            ];
        }

        $groups = [];
        $anyEditable = false;
        foreach (SettingsCatalog::GROUPS as $id => $label) {
            $editable = SettingsCatalog::canEditGroup($id, $actor);
            $anyEditable = $anyEditable || $editable;
            $groups[] = [
                'id' => $id,
                'label' => $label,
                'editable' => $editable,
                'items' => $grouped[$id] ?? [],
            ];
        }

        return [
            'readOnly' => !$anyEditable,
            'groups' => $groups,
            'note' => 'Company, general and retention settings can be changed by an Admin or a Super Admin. Security settings can be changed by a Super Admin only. Each change keeps the previous value.',
        ];
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input { values: object }
     */
    public function update(array $actor, array $input): array
    {
        Authorization::authorize($actor, 'settings.view');
        $errors = [];
        UserValidator::onlyFields($input, ['values'], $errors);
        $values = $input['values'] ?? null;
        if (!is_array($values) || $values === [] || array_is_list($values)) {
            $errors['values'] = 'Send the settings to change.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $definitions = SettingsCatalog::definitions();
        foreach ($values as $key => $raw) {
            if (!is_string($key) || !isset($definitions[$key])) {
                $errors[$key] = 'Unknown setting.';
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        foreach ($values as $key => $raw) {
            $group = (string) $definitions[$key]['group'];
            if (!SettingsCatalog::canEditGroup($group, $actor)) {
                throw HttpException::forbidden(
                    $group === 'security'
                        ? 'Only a Super Admin can change security settings.'
                        : 'You cannot change these settings.',
                    'settings_not_editable',
                );
            }
        }

        $current = $this->settings->values();
        $clean = [];
        foreach ($values as $key => $raw) {
            $parsed = SettingsCatalog::normalise((string) $key, $raw, array_merge($current, $clean));
            if ($parsed['error'] !== null) {
                $errors[(string) $key] = $parsed['error'];
                continue;
            }
            $clean[(string) $key] = $parsed['value'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $changed = [];
        foreach ($clean as $key => $value) {
            $old = $current[$key] ?? (string) $definitions[$key]['default'];
            if ($old !== $value) {
                $changed[$key] = ['old' => $old, 'new' => $value];
            }
        }
        if ($changed === []) {
            throw HttpException::validation(['values' => 'Nothing to update.']);
        }

        $now = UtcTime::now();
        $actorId = (int) $actor['id'];
        $this->settings->transaction(function () use ($changed, $actorId, $now, $definitions): void {
            foreach ($changed as $key => $pair) {
                $this->settings->updateValue($key, $pair['new'], $actorId, $now);
                $versionId = $this->settings->addVersion(
                    $key,
                    $pair['old'] === '' ? null : $pair['old'],
                    $pair['new'] === '' ? null : $pair['new'],
                    $actorId,
                    $now,
                    RequestContext::requestId(),
                );
                EntityHistory::recordDiff('setting', $versionId, (string) $definitions[$key]['label'], [
                    'Value' => $pair['old'] === '' ? null : $pair['old'],
                ], [
                    'Value' => $pair['new'] === '' ? null : $pair['new'],
                ], $actorId);
            }
            AuditLogger::record([
                'module' => 'settings',
                'action' => 'update',
                'targetType' => 'settings',
                'targetLabel' => implode(', ', array_keys($changed)),
                'details' => ['keys' => array_keys($changed)],
            ]);
        });

        return $this->view($actor);
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $query */
    public function history(array $actor, array $query): array
    {
        Authorization::authorize($actor, 'settings.view');
        $key = isset($query['key']) && is_string($query['key']) && $query['key'] !== '' ? $query['key'] : null;
        if ($key !== null && !isset(SettingsCatalog::definitions()[$key])) {
            throw HttpException::validation(['key' => 'Unknown setting.']);
        }
        $limit = isset($query['limit']) && ctype_digit((string) $query['limit']) ? (int) $query['limit'] : 50;
        $offset = isset($query['offset']) && ctype_digit((string) $query['offset']) ? (int) $query['offset'] : 0;
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $definitions = SettingsCatalog::definitions();

        return [
            'items' => array_map(static function (array $row) use ($definitions): array {
                $key = (string) $row['setting_key'];

                return [
                    'id' => (int) $row['id'],
                    'key' => $key,
                    'label' => $definitions[$key]['label'] ?? $key,
                    'oldValue' => $row['old_value'],
                    'newValue' => $row['new_value'],
                    'changedBy' => $row['changed_by'] !== null ? (int) $row['changed_by'] : null,
                    'changedByName' => $row['changed_by_name'] ?? null,
                    'changedAt' => UtcTime::iso($row['changed_at'] ?? null),
                ];
            }, $this->settings->history($key, $limit, $offset)),
            'total' => $this->settings->historyCount($key),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
