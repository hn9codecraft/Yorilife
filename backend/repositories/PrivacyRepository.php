<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use PDO;

/** Consent records and data-principal requests. Rows are updated or archived, never deleted. */
final class PrivacyRepository
{
    private function pdo(): PDO
    {
        return Database::connection();
    }

    /** @return array<string, mixed>|null */
    public function findEmployee(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, name, email, phone, status, role, anonymised_at, deleted_at, created_at, updated_at
             FROM employees WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findEmployeeByEmail(string $email): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, name, email, phone, status, role, anonymised_at FROM employees WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $values */
    public function insertConsent(array $values): int
    {
        $this->pdo()->prepare(
            'INSERT INTO privacy_consents (
                subject_type, subject_id, purpose, status, notice_version, channel, notes,
                recorded_by, recorded_at, withdrawn_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)'
        )->execute([
            $values['subject_type'],
            $values['subject_id'],
            $values['purpose'],
            $values['status'],
            $values['notice_version'],
            $values['channel'],
            $values['notes'],
            $values['recorded_by'],
            $values['withdrawn_at'],
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findConsent(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM privacy_consents WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function withdrawConsent(int $id): void
    {
        $this->pdo()->prepare(
            "UPDATE privacy_consents SET status = 'withdrawn', withdrawn_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'granted'"
        )->execute([$id]);
    }

    /** @return list<array<string, mixed>> */
    public function consentsFor(string $subjectType, int $subjectId, bool $includeArchived): array
    {
        $sql = 'SELECT * FROM privacy_consents WHERE subject_type = ? AND subject_id = ?';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$subjectType, $subjectId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function listConsents(bool $includeArchived, int $limit): array
    {
        $sql = 'SELECT * FROM privacy_consents';
        if (!$includeArchived) {
            $sql .= ' WHERE archived_at IS NULL';
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = $this->pdo()->query($sql);

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @param array<string, mixed> $values */
    public function insertRequest(array $values): int
    {
        $this->pdo()->prepare(
            'INSERT INTO privacy_requests (
                kind, subject_type, subject_id, status, detail, requested_by, requested_at
             ) VALUES (?, ?, ?, \'open\', ?, ?, UTC_TIMESTAMP())'
        )->execute([
            $values['kind'],
            $values['subject_type'],
            $values['subject_id'],
            $values['detail'],
            $values['requested_by'],
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findRequest(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM privacy_requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function closeRequest(int $id, string $status, int $handledBy, ?string $outcome): void
    {
        $this->pdo()->prepare(
            'UPDATE privacy_requests
                SET status = ?, outcome = ?, handled_by = ?, fulfilled_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = \'open\''
        )->execute([$status, $outcome, $handledBy, $id]);
    }

    /** @return list<array<string, mixed>> */
    public function requestsFor(string $subjectType, int $subjectId, bool $includeArchived): array
    {
        $sql = 'SELECT * FROM privacy_requests WHERE subject_type = ? AND subject_id = ?';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$subjectType, $subjectId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function listRequests(bool $includeArchived, int $limit): array
    {
        $sql = 'SELECT * FROM privacy_requests';
        if (!$includeArchived) {
            $sql .= ' WHERE archived_at IS NULL';
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = $this->pdo()->query($sql);

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    public function anonymiseEmployee(int $id, string $name, string $email): void
    {
        $this->pdo()->prepare(
            'UPDATE employees
                SET name = ?, email = ?, phone = ?, anonymised_at = UTC_TIMESTAMP()
              WHERE id = ?'
        )->execute([$name, $email, '**********', $id]);
    }

    public function maskSnapshots(string $subjectType, int $subjectId, string $name): void
    {
        $this->pdo()->prepare(
            'UPDATE announcement_recipients
                SET recipient_name = ?, recipient_contact = NULL
              WHERE recipient_type = ? AND recipient_id = ?'
        )->execute([$name, $subjectType, $subjectId]);

        $this->pdo()->prepare(
            "UPDATE entity_changes
                SET old_value = '[anonymised]', new_value = '[anonymised]'
              WHERE entity_type = ? AND entity_id = ?
                AND field IN ('Name', 'Email', 'Phone', 'Address', 'Password', 'Contact')"
        )->execute([$subjectType, $subjectId]);

        $this->pdo()->prepare(
            "UPDATE privacy_consents
                SET notes = CASE WHEN notes IS NULL OR notes = '' THEN notes ELSE '[anonymised]' END
              WHERE subject_type = ? AND subject_id = ?"
        )->execute([$subjectType, $subjectId]);

        $this->pdo()->prepare(
            "UPDATE privacy_requests
                SET detail = CASE WHEN detail IS NULL OR detail = '' THEN detail ELSE '[anonymised]' END,
                    outcome = CASE WHEN outcome IS NULL OR outcome = '' THEN outcome ELSE '[anonymised]' END
              WHERE subject_type = ? AND subject_id = ?"
        )->execute([$subjectType, $subjectId]);
    }
}
