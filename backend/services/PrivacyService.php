<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Repositories\PrivacyRepository;
use App\Repositories\TwoFactorRepository;
use App\Repositories\UserRepository;
use App\Utils\HttpException;

/**
 * India DPDP support: consent records, a person's data export, correction
 * tracking, and anonymising a person in place. Rows are masked, never deleted.
 * The audit hash chain is not rewritten. New audit events omit the original
 * personal values.
 */
final class PrivacyService
{
    /** @var list<string> */
    public const TYPES = ['user', 'employee', 'manager', 'headquarters', 'stockist'];

    /** @var list<string> */
    public const PURPOSES = ['account', 'communications', 'operations'];

    /** @var list<string> */
    public const KINDS = ['export', 'correction', 'anonymize'];

    /** @var list<string> */
    public const STATUSES = ['open', 'in_progress', 'completed', 'rejected'];

    public function __construct(
        private readonly PrivacyRepository $privacy = new PrivacyRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /** @param array<string, mixed> $actor */
    public function exportSelf(array $actor): array
    {
        $export = $this->collect('user', (int) $actor['id']);
        AuditLogger::record([
            'module' => 'privacy',
            'action' => 'privacy_export',
            'targetType' => 'user',
            'targetId' => (int) $actor['id'],
            'targetLabel' => 'Own record',
            'details' => ['self' => true],
        ]);

        return $export;
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $query */
    public function listConsents(array $actor, array $query): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        [$type, $id] = $this->optionalSubject($query);

        return ['items' => array_map($this->presentConsent(...), $this->privacy->consents($type, $id, 100))];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input */
    public function recordConsent(array $actor, array $input): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        $errors = [];
        UserValidator::onlyFields($input, ['subjectType', 'subjectId', 'purpose', 'granted', 'note'], $errors);
        $type = $this->subjectType($input['subjectType'] ?? null, $errors);
        $id = $this->subjectId($input['subjectId'] ?? null, $errors);
        $purpose = is_string($input['purpose'] ?? null) ? $input['purpose'] : '';
        if (!in_array($purpose, self::PURPOSES, true)) {
            $errors['purpose'] = 'Choose account, communications or operations.';
        }
        $granted = $this->bool($input['granted'] ?? null);
        if ($granted === null) {
            $errors['granted'] = 'Choose whether consent was given.';
        }
        $note = $this->note($input['note'] ?? null, $errors);
        if ($errors === [] && $type !== '' && $id > 0) {
            $this->assertSubject($type, $id);
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $now = UtcTime::now();
        $rowId = $this->privacy->insertConsent([
            'subject_type' => $type,
            'subject_id' => $id,
            'purpose' => $purpose,
            'granted' => $granted ? 1 : 0,
            'note' => $note,
            'recorded_by' => (int) $actor['id'],
            'recorded_at' => $now,
            'archived_at' => null,
        ]);
        AuditLogger::record([
            'module' => 'privacy',
            'action' => 'privacy_consent',
            'targetType' => $type,
            'targetId' => $id,
            'details' => ['purpose' => $purpose, 'granted' => $granted],
        ]);
        $row = $this->privacy->consents($type, $id, 1)[0] ?? null;
        if ($row === null) {
            $row = [
                'id' => $rowId,
                'subject_type' => $type,
                'subject_id' => $id,
                'purpose' => $purpose,
                'granted' => $granted ? 1 : 0,
                'note' => $note,
                'recorded_by' => (int) $actor['id'],
                'recorded_at' => $now,
                'recorded_by_name' => $actor['name'] ?? null,
                'archived_at' => null,
            ];
        }

        return $this->presentConsent($row);
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $query */
    public function listRequests(array $actor, array $query): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        [$type, $id] = $this->optionalSubject($query);
        $status = null;
        if (isset($query['status']) && $query['status'] !== '') {
            if (!is_string($query['status']) || !in_array($query['status'], self::STATUSES, true)) {
                throw HttpException::validation(['status' => 'Choose a valid request status.']);
            }
            $status = $query['status'];
        }

        return [
            'items' => array_map(
                $this->presentRequest(...),
                $this->privacy->requests(['subjectType' => $type, 'subjectId' => $id, 'status' => $status], 100),
            ),
        ];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input */
    public function openRequest(array $actor, array $input): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        $errors = [];
        UserValidator::onlyFields($input, ['subjectType', 'subjectId', 'kind', 'details'], $errors);
        $type = $this->subjectType($input['subjectType'] ?? null, $errors);
        $id = $this->subjectId($input['subjectId'] ?? null, $errors);
        $kind = is_string($input['kind'] ?? null) ? $input['kind'] : '';
        if (!in_array($kind, self::KINDS, true)) {
            $errors['kind'] = 'Choose export, correction or anonymize.';
        }
        $details = $this->longText($input['details'] ?? null, 'details', $kind === 'correction', $errors);
        if ($errors === [] && $type !== '' && $id > 0) {
            $this->assertSubject($type, $id);
        }
        if ((int) ($actor['id'] ?? 0) === $id && $type === 'user' && $kind === 'anonymize') {
            throw HttpException::forbidden('You cannot anonymise your own account.', 'cannot_anonymize_self');
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $now = UtcTime::now();
        $requestId = $this->privacy->insertRequest([
            'subject_type' => $type,
            'subject_id' => $id,
            'kind' => $kind,
            'status' => 'open',
            'details' => $details,
            'resolution' => null,
            'requested_by' => (int) $actor['id'],
            'resolved_by' => null,
            'requested_at' => $now,
            'resolved_at' => null,
            'archived_at' => null,
        ]);
        AuditLogger::record([
            'module' => 'privacy',
            'action' => 'privacy_request',
            'targetType' => $type,
            'targetId' => $id,
            'details' => ['kind' => $kind, 'requestId' => $requestId],
        ]);

        return $this->presentRequest($this->privacy->findRequest($requestId) ?? throw HttpException::notFound('Privacy request not found.'));
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input */
    public function updateRequest(array $actor, int $id, array $input): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        $row = $this->privacy->findRequest($id) ?? throw HttpException::notFound('Privacy request not found.');
        $errors = [];
        UserValidator::onlyFields($input, ['status', 'resolution'], $errors);
        $status = is_string($input['status'] ?? null) ? $input['status'] : '';
        if (!in_array($status, self::STATUSES, true)) {
            $errors['status'] = 'Choose open, in progress, completed or rejected.';
        }
        $needsResolution = in_array($status, ['completed', 'rejected'], true);
        $resolution = $this->longText($input['resolution'] ?? null, 'resolution', $needsResolution, $errors);
        if ($row['kind'] === 'anonymize' && $status === 'completed') {
            $errors['status'] = 'Complete an anonymise request with the anonymise action.';
        }
        if ($row['kind'] === 'export' && $status === 'completed') {
            $errors['status'] = 'Complete an export request with the export action.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $fields = ['status' => $status, 'resolution' => $resolution];
        if ($needsResolution) {
            $fields['resolved_by'] = (int) $actor['id'];
            $fields['resolved_at'] = UtcTime::now();
        }
        $this->privacy->updateRequest($id, $fields);
        AuditLogger::record([
            'module' => 'privacy',
            'action' => 'privacy_request_update',
            'targetType' => $row['subject_type'],
            'targetId' => (int) $row['subject_id'],
            'details' => ['requestId' => $id, 'kind' => $row['kind'], 'status' => $status],
        ]);

        return $this->presentRequest($this->privacy->findRequest($id) ?? $row);
    }

    /** @param array<string, mixed> $actor */
    public function fulfillExport(array $actor, int $id): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        $row = $this->requireKind($id, 'export');
        $export = $this->collect((string) $row['subject_type'], (int) $row['subject_id']);
        if ($row['status'] !== 'completed') {
            $this->privacy->updateRequest($id, [
                'status' => 'completed',
                'resolved_by' => (int) $actor['id'],
                'resolved_at' => UtcTime::now(),
                'resolution' => $row['resolution'] ?? 'Personal data exported.',
            ]);
        }
        AuditLogger::record([
            'module' => 'privacy',
            'action' => 'privacy_export',
            'targetType' => $row['subject_type'],
            'targetId' => (int) $row['subject_id'],
            'details' => ['requestId' => $id],
        ]);

        return [
            'request' => $this->presentRequest($this->privacy->findRequest($id) ?? $row),
            'export' => $export,
        ];
    }

    /** @param array<string, mixed> $actor */
    public function fulfillAnonymize(array $actor, int $id): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        $row = $this->requireKind($id, 'anonymize');
        if ($row['status'] === 'completed') {
            throw new HttpException(409, 'already_completed', 'This anonymise request is already complete.');
        }
        $type = (string) $row['subject_type'];
        $subjectId = (int) $row['subject_id'];
        if ($type === 'user' && $subjectId === (int) $actor['id']) {
            throw HttpException::forbidden('You cannot anonymise your own account.', 'cannot_anonymize_self');
        }
        $this->mask($type, $subjectId);
        $this->privacy->updateRequest($id, [
            'status' => 'completed',
            'resolved_by' => (int) $actor['id'],
            'resolved_at' => UtcTime::now(),
            'resolution' => 'Personal data masked in place. The record was kept.',
        ]);
        AuditLogger::record([
            'module' => 'privacy',
            'action' => 'privacy_anonymize',
            'targetType' => $type,
            'targetId' => $subjectId,
            'targetLabel' => 'Anonymised',
            'details' => ['requestId' => $id],
        ]);
        EntityHistory::recordDiff($type, $subjectId, 'Anonymised', [
            'Identity' => '(personal data)',
        ], [
            'Identity' => '(anonymised)',
        ], (int) $actor['id']);

        return $this->presentRequest($this->privacy->findRequest($id) ?? $row);
    }

    /** @return array<string, mixed> */
    private function collect(string $type, int $id): array
    {
        $this->assertSubject($type, $id);
        $profile = $this->profile($type, $id);

        return [
            'exportedAt' => UtcTime::iso(UtcTime::now()),
            'subjectType' => $type,
            'subjectId' => $id,
            'profile' => $profile,
            'consents' => array_map($this->presentConsent(...), $this->privacy->consents($type, $id, 100)),
            'requests' => array_map($this->presentRequest(...), $this->privacy->requests([
                'subjectType' => $type,
                'subjectId' => $id,
                'status' => null,
            ], 100)),
        ];
    }

    /** @return array<string, mixed> */
    private function profile(string $type, int $id): array
    {
        $pdo = Database::connection();
        $sql = match ($type) {
            'user' => 'SELECT id, name, email, status, last_login_at, created_at FROM users WHERE id = ?',
            'employee' => 'SELECT id, name, email, phone, role, status, created_at FROM employees WHERE id = ?',
            'manager' => 'SELECT id, level, name, code, email, phone, created_at FROM managers WHERE id = ?',
            'headquarters' => 'SELECT id, name, code, state, address, pincode, phone, email FROM headquarters WHERE id = ?',
            'stockist' => 'SELECT id, name, code, address, pincode, phone, email FROM stockists WHERE id = ?',
            default => throw HttpException::validation(['subjectType' => 'Choose a valid person or contact.']),
        };
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw HttpException::notFound('That record was not found.');
        }
        $out = [];
        foreach ($row as $key => $value) {
            $out[$this->camel($key)] = $value;
        }

        return $out;
    }

    private function mask(string $type, int $id): void
    {
        $this->assertSubject($type, $id);
        $pdo = Database::connection();
        $email = 'anonymised-' . $type . '-' . $id . '@redacted.invalid';
        match ($type) {
            'user' => $this->maskUser($id, $email),
            'employee' => $pdo->prepare(
                "UPDATE employees SET name = 'Anonymised', email = NULL, phone = NULL WHERE id = ?"
            )->execute([$id]),
            'manager' => $pdo->prepare(
                "UPDATE managers SET name = 'Anonymised', email = NULL, phone = NULL WHERE id = ?"
            )->execute([$id]),
            'headquarters' => $pdo->prepare(
                'UPDATE headquarters SET address = NULL, pincode = NULL, phone = NULL, email = NULL WHERE id = ?'
            )->execute([$id]),
            'stockist' => $pdo->prepare(
                'UPDATE stockists SET address = NULL, pincode = NULL, phone = NULL, email = NULL WHERE id = ?'
            )->execute([$id]),
            default => throw HttpException::validation(['subjectType' => 'Choose a valid person or contact.']),
        };
        $this->redactHistory($type, $id);
    }

    private function maskUser(int $id, string $email): void
    {
        $this->users->update($id, [
            'name' => 'Anonymised',
            'email' => $email,
            'status' => 'disabled',
        ]);
        $this->users->updatePasswordHash($id, PasswordHasher::hash(bin2hex(random_bytes(24))));
        (new TwoFactorRepository())->clear($id);
        AccessRevoker::revoke($id);
    }

    private function redactHistory(string $type, int $id): void
    {
        Database::connection()->prepare(
            "UPDATE entity_changes
             SET entity_label = 'Anonymised',
                 old_value = IF(LOWER(field) REGEXP 'email|phone|mobile|address|pincode|name', '[redacted]', old_value),
                 new_value = IF(LOWER(field) REGEXP 'email|phone|mobile|address|pincode|name', '[redacted]', new_value)
             WHERE entity_type = ? AND entity_id = ?"
        )->execute([$type, $id]);
    }

    /** @return array<string, mixed> */
    private function requireKind(int $id, string $kind): array
    {
        $row = $this->privacy->findRequest($id) ?? throw HttpException::notFound('Privacy request not found.');
        if ($row['kind'] !== $kind) {
            throw HttpException::validation(['kind' => 'This request is not a ' . $kind . ' request.']);
        }

        return $row;
    }

    private function assertSubject(string $type, int $id): void
    {
        $table = match ($type) {
            'user' => 'users',
            'employee' => 'employees',
            'manager' => 'managers',
            'headquarters' => 'headquarters',
            'stockist' => 'stockists',
            default => throw HttpException::validation(['subjectType' => 'Choose a valid person or contact.']),
        };
        $stmt = Database::connection()->prepare("SELECT id FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            throw HttpException::notFound('That record was not found.');
        }
    }

    /** @param array<string, mixed> $query @return array{0: ?string, 1: ?int} */
    private function optionalSubject(array $query): array
    {
        $hasType = isset($query['subjectType']) && $query['subjectType'] !== '';
        $hasId = isset($query['subjectId']) && $query['subjectId'] !== '';
        if (!$hasType && !$hasId) {
            return [null, null];
        }
        $errors = [];
        $type = $this->subjectType($query['subjectType'] ?? null, $errors);
        $id = $this->subjectId($query['subjectId'] ?? null, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [$type, $id];
    }

    /** @param array<string, string> $errors */
    private function subjectType(mixed $raw, array &$errors): string
    {
        if (!is_string($raw) || !in_array($raw, self::TYPES, true)) {
            $errors['subjectType'] = 'Choose a user, employee, manager, headquarters or stockist.';

            return '';
        }

        return $raw;
    }

    /** @param array<string, string> $errors */
    private function subjectId(mixed $raw, array &$errors): int
    {
        if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
            $errors['subjectId'] = 'Enter the record id.';

            return 0;
        }
        $id = (int) $raw;
        if ($id < 1) {
            $errors['subjectId'] = 'Enter the record id.';

            return 0;
        }

        return $id;
    }

    private function bool(mixed $raw): ?bool
    {
        if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') {
            return true;
        }
        if ($raw === false || $raw === 0 || $raw === '0' || $raw === 'false') {
            return false;
        }

        return null;
    }

    /** @param array<string, string> $errors */
    private function note(mixed $raw, array &$errors): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || mb_strlen(trim($raw)) > 255) {
            $errors['note'] = 'The note must be 255 characters or fewer.';

            return null;
        }

        return trim($raw);
    }

    /** @param array<string, string> $errors */
    private function longText(mixed $raw, string $field, bool $required, array &$errors): ?string
    {
        if ($raw === null || $raw === '') {
            if ($required) {
                $errors[$field] = 'Enter the details of this request.';
            }

            return null;
        }
        if (!is_string($raw)) {
            $errors[$field] = 'Enter text.';

            return null;
        }
        $text = trim($raw);
        if (mb_strlen($text) > 2000) {
            $errors[$field] = 'Use 2000 characters or fewer.';

            return null;
        }

        return $text === '' ? null : $text;
    }

    /** @param array<string, mixed> $row */
    private function presentConsent(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'subjectType' => $row['subject_type'],
            'subjectId' => (int) $row['subject_id'],
            'purpose' => $row['purpose'],
            'granted' => (int) $row['granted'] === 1,
            'note' => $row['note'] ?? null,
            'recordedBy' => isset($row['recorded_by']) && $row['recorded_by'] !== null ? (int) $row['recorded_by'] : null,
            'recordedByName' => $row['recorded_by_name'] ?? null,
            'recordedAt' => UtcTime::iso($row['recorded_at'] ?? null),
            'archived' => !empty($row['archived_at']),
        ];
    }

    /** @param array<string, mixed> $row */
    private function presentRequest(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'subjectType' => $row['subject_type'],
            'subjectId' => (int) $row['subject_id'],
            'kind' => $row['kind'],
            'status' => $row['status'],
            'details' => $row['details'] ?? null,
            'resolution' => $row['resolution'] ?? null,
            'requestedByName' => $row['requested_by_name'] ?? null,
            'resolvedByName' => $row['resolved_by_name'] ?? null,
            'requestedAt' => UtcTime::iso($row['requested_at'] ?? null),
            'resolvedAt' => UtcTime::iso($row['resolved_at'] ?? null),
            'archived' => !empty($row['archived_at']),
        ];
    }

    private function camel(string $key): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
    }
}
