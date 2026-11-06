<?php

declare(strict_types=1);

namespace App\Repositories;

/** SQL for announcements, their audience rules, recipients, files, templates, history and outbox. */
final class AnnouncementRepository extends Repository
{
    private const COLUMNS = 'a.id, a.title, a.body_html, a.body_text, a.type, a.priority, a.status, a.banner_attachment_id,
        a.requires_ack, a.is_pinned, a.is_updated, a.audience_all, a.publish_at, a.published_at, a.expires_at, a.recalled_at,
        a.recall_reason, a.recipient_count, a.version, a.created_by, a.updated_by, a.published_by, a.recalled_by,
        a.created_at, a.updated_at,
        cu.name AS created_by_name, uu.name AS updated_by_name, pu.name AS published_by_name, ru.name AS recalled_by_name';

    private const JOINS = 'FROM announcements a
        LEFT JOIN users cu ON cu.id = a.created_by
        LEFT JOIN users uu ON uu.id = a.updated_by
        LEFT JOIN users pu ON pu.id = a.published_by
        LEFT JOIN users ru ON ru.id = a.recalled_by';

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->many(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . "
             WHERE a.deleted_at IS NULL
             ORDER BY (a.is_pinned = 1 AND a.status = 'published') DESC, COALESCE(a.published_at, a.publish_at, a.updated_at) DESC, a.id DESC"
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' ' . self::JOINS . ' WHERE a.id = ? AND a.deleted_at IS NULL', [$id]);
    }

    /** @return array<string, mixed>|null row locked for update (inside a transaction) */
    public function lock(int $id): ?array
    {
        return $this->one('SELECT id, status, version FROM announcements WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
    }

    public function insert(array $fields): int
    {
        return $this->insertRow('announcements', $fields);
    }

    public function update(int $id, array $fields): void
    {
        $this->updateRow('announcements', $id, $fields);
    }

    /** @return array<string, list<string>> rule type => values */
    public function rules(int $id): array
    {
        $rules = [];
        foreach ($this->many('SELECT rule_type, rule_value FROM announcement_audience_rules WHERE announcement_id = ? ORDER BY id', [$id]) as $row) {
            $rules[$row['rule_type']][] = (string) $row['rule_value'];
        }

        return $rules;
    }

    /** @param array<string, list<string>> $rules */
    public function replaceRules(int $id, array $rules): void
    {
        $this->pdo()->prepare('DELETE FROM announcement_audience_rules WHERE announcement_id = ?')->execute([$id]);
        $insert = $this->pdo()->prepare('INSERT INTO announcement_audience_rules (announcement_id, rule_type, rule_value) VALUES (?, ?, ?)');
        foreach ($rules as $type => $values) {
            foreach ($values as $value) {
                $insert->execute([$id, $type, (string) $value]);
            }
        }
    }

    public function logChange(int $id, string $action, ?array $changes, ?int $userId, string $at): void
    {
        $this->insertRow('announcement_changes', [
            'announcement_id' => $id,
            'action' => $action,
            'changes' => $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'changed_by' => $userId,
            'changed_at' => $at,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function changes(int $id): array
    {
        return $this->many(
            'SELECT c.id, c.action, c.changes, c.changed_at, u.name AS changed_by_name
             FROM announcement_changes c LEFT JOIN users u ON u.id = c.changed_by
             WHERE c.announcement_id = ? ORDER BY c.changed_at DESC, c.id DESC',
            [$id]
        );
    }

    // ---- Recipients ---------------------------------------------------------

    /** @param list<array{type: string, id: int, name: string, contact: ?string}> $people */
    public function freezeRecipients(int $id, array $people, string $at): void
    {
        $this->pdo()->prepare('DELETE FROM announcement_recipients WHERE announcement_id = ?')->execute([$id]);
        $insert = $this->pdo()->prepare('INSERT INTO announcement_recipients (announcement_id, recipient_type, recipient_id, recipient_name, recipient_contact, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($people as $person) {
            $insert->execute([$id, $person['type'], $person['id'], mb_substr($person['name'], 0, 150), $person['contact'] === null ? null : mb_substr($person['contact'], 0, 254), $at]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function recipients(int $id): array
    {
        return $this->many(
            'SELECT recipient_type, recipient_id, recipient_name, recipient_contact, delivered_at, read_at, acknowledged_at
             FROM announcement_recipients WHERE announcement_id = ? ORDER BY recipient_type, recipient_name, recipient_id',
            [$id]
        );
    }

    /** @return array{total: int, delivered: int, read: int, acknowledged: int} */
    public function recipientCounts(int $id): array
    {
        $row = $this->one(
            'SELECT COUNT(*) AS total, COUNT(delivered_at) AS delivered, COUNT(read_at) AS `read`, COUNT(acknowledged_at) AS acknowledged
             FROM announcement_recipients WHERE announcement_id = ?',
            [$id]
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'delivered' => (int) ($row['delivered'] ?? 0),
            'read' => (int) ($row['read'] ?? 0),
            'acknowledged' => (int) ($row['acknowledged'] ?? 0),
        ];
    }

    // ---- Outbox -------------------------------------------------------------

    /** One pending event per recipient (sent later by the employee app sender). */
    public function queueOutbox(int $id, string $event, array $payload, string $at): int
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $statement = $this->pdo()->prepare(
            "INSERT INTO notification_outbox (event, announcement_id, recipient_type, recipient_id, payload, status, created_at)
             SELECT ?, announcement_id, recipient_type, recipient_id, ?, 'pending', ? FROM announcement_recipients WHERE announcement_id = ?"
        );
        $statement->execute([$event, $json, $at, $id]);

        return $statement->rowCount();
    }

    /** @return array<string, int> event => count */
    public function outboxCounts(int $id): array
    {
        $counts = [];
        foreach ($this->many('SELECT event, COUNT(*) AS total FROM notification_outbox WHERE announcement_id = ? GROUP BY event', [$id]) as $row) {
            $counts[$row['event']] = (int) $row['total'];
        }

        return $counts;
    }

    // ---- Scheduler ----------------------------------------------------------

    /** @return list<int> */
    public function dueScheduled(string $now): array
    {
        return array_map('intval', array_column($this->many("SELECT id FROM announcements WHERE status = 'scheduled' AND deleted_at IS NULL AND publish_at <= ? ORDER BY publish_at, id", [$now]), 'id'));
    }

    /** @return list<int> */
    public function dueExpiry(string $now): array
    {
        return array_map('intval', array_column($this->many("SELECT id FROM announcements WHERE status = 'published' AND deleted_at IS NULL AND expires_at IS NOT NULL AND expires_at <= ? ORDER BY expires_at, id", [$now]), 'id'));
    }

    // ---- Attachments --------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function attachments(int $id): array
    {
        return $this->many(
            'SELECT id, announcement_id, kind, original_name, stored_path, mime, size_bytes, sha256, created_at
             FROM announcement_attachments WHERE announcement_id = ? AND deleted_at IS NULL ORDER BY kind, id',
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function attachment(int $announcementId, int $attachmentId): ?array
    {
        return $this->one(
            'SELECT id, announcement_id, kind, original_name, stored_path, mime, size_bytes, sha256, created_at
             FROM announcement_attachments WHERE id = ? AND announcement_id = ? AND deleted_at IS NULL',
            [$attachmentId, $announcementId]
        );
    }

    public function insertAttachment(array $fields): int
    {
        return $this->insertRow('announcement_attachments', $fields);
    }

    public function removeAttachment(int $attachmentId, string $at): void
    {
        $this->pdo()->prepare('UPDATE announcement_attachments SET deleted_at = ? WHERE id = ?')->execute([$at, $attachmentId]);
    }

    // ---- Templates ----------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function templates(): array
    {
        return $this->many(
            'SELECT t.id, t.name, t.type, t.priority, t.title, t.body_html, t.requires_ack, t.created_at, t.updated_at, u.name AS created_by_name
             FROM announcement_templates t LEFT JOIN users u ON u.id = t.created_by
             WHERE t.deleted_at IS NULL ORDER BY t.name, t.id'
        );
    }

    /** @return array<string, mixed>|null */
    public function template(int $id): ?array
    {
        return $this->one(
            'SELECT t.id, t.name, t.type, t.priority, t.title, t.body_html, t.requires_ack, t.created_at, t.updated_at, u.name AS created_by_name
             FROM announcement_templates t LEFT JOIN users u ON u.id = t.created_by
             WHERE t.id = ? AND t.deleted_at IS NULL',
            [$id]
        );
    }

    public function insertTemplate(array $fields): int
    {
        return $this->insertRow('announcement_templates', $fields);
    }

    public function updateTemplate(int $id, array $fields): void
    {
        $this->updateRow('announcement_templates', $id, $fields);
    }

    // ---- Audience sources ---------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function managers(): array
    {
        return $this->many('SELECT id, level, name, code, email, phone, parent_manager_id FROM managers ORDER BY level DESC, name, id');
    }

    /** @return list<array{manager_id: int, location_id: int}> */
    public function managerLocations(): array
    {
        return $this->many('SELECT manager_id, location_id FROM manager_states');
    }

    /** @return list<array<string, mixed>> */
    public function headquarters(): array
    {
        return $this->many('SELECT id, name, code, location_id, parent_manager_id FROM headquarters ORDER BY name, id');
    }

    /** @return list<array<string, mixed>> */
    public function locations(): array
    {
        return $this->many('SELECT id, name, code FROM locations ORDER BY name, id');
    }

    /** @return list<array<string, mixed>> active ERP users with their role */
    public function activeUsers(): array
    {
        return $this->many(
            "SELECT u.id, u.name, u.email, r.slug AS role_slug, r.name AS role_name
             FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.status = 'active' ORDER BY u.name, u.id"
        );
    }

    /** @return list<array<string, mixed>> */
    public function roles(): array
    {
        return $this->many('SELECT slug, name FROM roles ORDER BY id');
    }

    /** @return list<array<string, mixed>> active employees with the location of their HQ */
    public function activeEmployees(): array
    {
        return $this->many(
            "SELECT e.id, e.name, e.email, e.phone, e.role, e.location_id, e.headquarters_id, h.location_id AS hq_location_id
             FROM employees e LEFT JOIN headquarters h ON h.id = e.headquarters_id
             WHERE e.deleted_at IS NULL AND e.status = 'active' ORDER BY e.name, e.id"
        );
    }

    /** @return list<array<string, mixed>> */
    public function employeeChoices(): array
    {
        return $this->many("SELECT id, name, role, status FROM employees WHERE deleted_at IS NULL ORDER BY name, id");
    }
}