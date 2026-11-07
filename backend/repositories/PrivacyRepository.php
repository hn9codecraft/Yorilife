<?php

declare(strict_types=1);

namespace App\Repositories;

final class PrivacyRepository extends Repository
{
    /** @param array<string, mixed> $fields */
    public function insertConsent(array $fields): int
    {
        return $this->insertRow('privacy_consents', $fields);
    }

    /** @return list<array<string, mixed>> */
    public function consents(?string $type, ?int $subjectId, int $limit): array
    {
        $where = '';
        $params = [];
        if ($type !== null && $subjectId !== null) {
            $where = ' WHERE c.subject_type = ? AND c.subject_id = ?';
            $params = [$type, $subjectId];
        }

        return $this->many(
            'SELECT c.id, c.subject_type, c.subject_id, c.purpose, c.granted, c.note, c.recorded_by, c.recorded_at, c.archived_at,
                    u.name AS recorded_by_name
             FROM privacy_consents c
             LEFT JOIN users u ON u.id = c.recorded_by'
            . $where
            . ' ORDER BY c.id DESC LIMIT ' . $limit,
            $params
        );
    }

    /** @param array<string, mixed> $fields */
    public function insertRequest(array $fields): int
    {
        return $this->insertRow('privacy_requests', $fields);
    }

    /** @return array<string, mixed>|null */
    public function findRequest(int $id): ?array
    {
        return $this->one(
            'SELECT r.id, r.subject_type, r.subject_id, r.kind, r.status, r.details, r.resolution,
                    r.requested_by, r.resolved_by, r.requested_at, r.resolved_at, r.archived_at,
                    u.name AS requested_by_name, resolver.name AS resolved_by_name
             FROM privacy_requests r
             LEFT JOIN users u ON u.id = r.requested_by
             LEFT JOIN users resolver ON resolver.id = r.resolved_by
             WHERE r.id = ?',
            [$id]
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function requests(array $filters, int $limit): array
    {
        $where = [];
        $params = [];
        if (($filters['subjectType'] ?? null) !== null) {
            $where[] = 'r.subject_type = ?';
            $params[] = $filters['subjectType'];
            $where[] = 'r.subject_id = ?';
            $params[] = $filters['subjectId'];
        }
        if (($filters['status'] ?? null) !== null) {
            $where[] = 'r.status = ?';
            $params[] = $filters['status'];
        }
        $sql = 'SELECT r.id, r.subject_type, r.subject_id, r.kind, r.status, r.details, r.resolution,
                       r.requested_by, r.resolved_by, r.requested_at, r.resolved_at, r.archived_at,
                       u.name AS requested_by_name, resolver.name AS resolved_by_name
                FROM privacy_requests r
                LEFT JOIN users u ON u.id = r.requested_by
                LEFT JOIN users resolver ON resolver.id = r.resolved_by';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY r.id DESC LIMIT ' . $limit;

        return $this->many($sql, $params);
    }

    /** @param array<string, mixed> $fields */
    public function updateRequest(int $id, array $fields): void
    {
        $this->updateRow('privacy_requests', $id, $fields);
    }
}
