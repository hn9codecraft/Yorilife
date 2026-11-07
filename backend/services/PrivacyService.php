<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ApiTokenRepository;
use App\Repositories\PrivacyRepository;
use App\Repositories\TwoFactorRepository;
use App\Repositories\UserRepository;
use App\Utils\HttpException;

/**
 * DPDP support: consent, access export, correction tracking, and anonymising
 * a person in place. Rows are masked or updated. They are not deleted.
 * The audit hash chain is not rewritten.
 */
final class PrivacyService
{
    /** @var list<string> */
    private const PURPOSES = ['account', 'announcements', 'hr', 'sales_operations'];

    /** @var list<string> */
    private const CHANNELS = ['admin', 'paper', 'electronic'];

    public function __construct(
        private readonly PrivacyRepository $privacy = new PrivacyRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $query */
    public function listConsents(array $actor, array $query): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        $include = ($query['archived'] ?? '') === '1';
        if (isset($query['subjectType'], $query['subjectId']) && is_string($query['subjectType']) && ctype_digit((string) $query['subjectId'])) {
            $type = $this->subjectType($query['subjectType']);
            $rows = $this->privacy->consentsFor($type, (int) $query['subjectId'], $include);
        } else {
            $rows = $this->privacy->listConsents($include, 200);
        }

        return ['consents' => array_map([$this, 'presentConsent'], $rows)];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input */
    public function recordConsent(array $actor, array $input): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        $errors = [];
        UserValidator::onlyFields($input, ['subjectType', 'subjectId', 'purpose', 'status', 'noticeVersion', 'channel', 'notes'], $errors);
        $subject = $this->requireSubject($input, $errors);
        $purpose = $this->oneOf($input['purpose'] ?? null, self::PURPOSES, 'purpose', 'Choose a purpose.', $errors);
        $status = $this->oneOf($input['status'] ?? 'granted', ['granted', 'withdrawn'], 'status', 'Choose granted or withdrawn.', $errors);
        $channel = $this->oneOf($input['channel'] ?? 'admin', self::CHANNELS, 'channel', 'Choose how consent was given.', $errors);
        $notice = $this->shortText($input['noticeVersion'] ?? null, 'noticeVersion', 40, false, $errors);
        $notes = $this->shortText($input['notes'] ?? null, 'notes', 500, false, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = $this->privacy->insertConsent([
            'subject_type' => $subject['type'],
            'subject_id' => $subject['id'],
            'purpose' => $purpose,
            'status' => $status,
            'notice_version' => $notice,
            'channel' => $channel,
            'notes' => $notes,
            'recorded_by' => (int) $actor['id'],
            'withdrawn_at' => $status === 'withdrawn' ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $this->audit('privacy_consent_recorded', $subject, ['purpose' => $purpose, 'status' => $status, 'consentId' => $id]);

        return ['consent' => $this->presentConsent($this->privacy->findConsent($id) ?? [])];
    }

    /** @param array<string, mixed> $actor */
    public function withdrawConsent(array $actor, int $id): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        $row = $this->privacy->findConsent($id) ?? throw HttpException::notFound('Consent record not found.');
        if ($row['status'] !== 'granted') {
            throw new HttpException(409, 'consent_already_withdrawn', 'This consent is already withdrawn.');
        }
        $this->privacy->withdrawConsent($id);
        $subject = $this->loadSubject((string) $row['subject_type'], (int) $row['subject_id']);
        $this->audit('privacy_consent_withdrawn', $subject, ['purpose' => $row['purpose'], 'consentId' => $id]);

        return ['consent' => $this->presentConsent($this->privacy->findConsent($id) ?? [])];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $query */
    public function listRequests(array $actor, array $query): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        $include = ($query['archived'] ?? '') === '1';
        if (isset($query['subjectType'], $query['subjectId']) && is_string($query['subjectType']) && ctype_digit((string) $query['subjectId'])) {
            $type = $this->subjectType($query['subjectType']);
            $rows = $this->privacy->requestsFor($type, (int) $query['subjectId'], $include);
        } else {
            $rows = $this->privacy->listRequests($include, 200);
        }

        return ['requests' => array_map([$this, 'presentRequest'], $rows)];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input */
    public function openRequest(array $actor, array $input): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        $errors = [];
        UserValidator::onlyFields($input, ['kind', 'subjectType', 'subjectId', 'detail'], $errors);
        $subject = $this->requireSubject($input, $errors);
        $kind = $this->oneOf($input['kind'] ?? null, ['export', 'correction', 'anonymize'], 'kind', 'Choose export, correction or anonymize.', $errors);
        $detail = $this->shortText($input['detail'] ?? null, 'detail', 2000, $kind === 'correction', $errors);
        if ($kind === 'correction' && $detail === null && !isset($errors['detail'])) {
            $errors['detail'] = 'Describe the correction the person asked for.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = $this->privacy->insertRequest([
            'kind' => $kind,
            'subject_type' => $subject['type'],
            'subject_id' => $subject['id'],
            'detail' => $detail,
            'requested_by' => (int) $actor['id'],
        ]);
        $this->audit('privacy_request_opened', $subject, ['kind' => $kind, 'requestId' => $id]);

        return ['request' => $this->presentRequest($this->privacy->findRequest($id) ?? [])];
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function fulfill(array $actor, int $id, array $input): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        $errors = [];
        UserValidator::onlyFields($input, ['outcome', 'confirm'], $errors);
        $request = $this->openOrFail($id);
        $subject = $this->loadSubject((string) $request['subject_type'], (int) $request['subject_id']);
        $kind = (string) $request['kind'];
        $export = null;
        if ($kind === 'export') {
            $export = $this->exportSubject($subject);
        } elseif ($kind === 'correction') {
            $outcome = $this->shortText($input['outcome'] ?? null, 'outcome', 500, true, $errors);
            if ($outcome === null && !isset($errors['outcome'])) {
                $errors['outcome'] = 'Record what was done with the correction.';
            }
        } elseif ($kind === 'anonymize') {
            if (($input['confirm'] ?? null) !== true) {
                $errors['confirm'] = 'Confirm that personal data should be masked. The record is kept.';
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        if ($kind === 'anonymize') {
            $this->anonymise($actor, $subject);
            $outcome = 'Personal data was masked in place. The record was kept.';
            $subject = $this->loadSubject($subject['type'], $subject['id']);
        } elseif ($kind === 'export') {
            $outcome = 'Personal data export was produced.';
        } else {
            $outcome = $this->shortText($input['outcome'] ?? null, 'outcome', 500, true, $errors);
        }

        $this->privacy->closeRequest($id, 'fulfilled', (int) $actor['id'], $outcome);
        $action = $kind === 'export' ? 'privacy_exported' : ($kind === 'anonymize' ? 'privacy_anonymised' : 'privacy_request_fulfilled');
        $this->audit($action, $subject, ['kind' => $kind, 'requestId' => $id]);

        $payload = ['request' => $this->presentRequest($this->privacy->findRequest($id) ?? [])];
        if ($export !== null) {
            $payload['export'] = $export;
        }
        if ($kind === 'anonymize') {
            $payload['subject'] = $this->publicSubject($subject);
        }

        return $payload;
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input */
    public function reject(array $actor, int $id, array $input): array
    {
        Authorization::authorize($actor, 'privacy.manage');
        $errors = [];
        UserValidator::onlyFields($input, ['outcome'], $errors);
        $outcome = $this->shortText($input['outcome'] ?? null, 'outcome', 500, true, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $request = $this->openOrFail($id);
        $subject = $this->loadSubject((string) $request['subject_type'], (int) $request['subject_id']);
        $this->privacy->closeRequest($id, 'rejected', (int) $actor['id'], $outcome);
        $this->audit('privacy_request_rejected', $subject, ['kind' => $request['kind'], 'requestId' => $id]);

        return ['request' => $this->presentRequest($this->privacy->findRequest($id) ?? [])];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $subject */
    private function anonymise(array $actor, array $subject): void
    {
        if ($subject['anonymised']) {
            throw new HttpException(409, 'already_anonymised', 'This person has already been anonymised.');
        }
        $originalEmail = is_string($subject['email']) ? $subject['email'] : '';
        $label = 'Anonymised person ' . $subject['id'];
        $email = $subject['type'] === 'user'
            ? 'anonymised-user-' . $subject['id'] . '@anonymised.invalid'
            : 'anonymised-employee-' . $subject['id'] . '@anonymised.invalid';
        if ($subject['type'] === 'user') {
            CredentialRevoker::revoke($subject['id']);
            $this->users->anonymise($subject['id'], $label, $email, PasswordHasher::hash(bin2hex(random_bytes(16))));
            (new TwoFactorRepository())->clear($subject['id']);
            (new TwoFactorRepository())->retireRecoveryCodes($subject['id']);
        } else {
            $this->privacy->anonymiseEmployee($subject['id'], $label, $email);
        }
        $this->privacy->maskSnapshots($subject['type'], $subject['id'], $label);
        EntityHistory::recordDiff($subject['type'], $subject['id'], $label, [
            'Name' => '[anonymised]',
            'Email' => '[anonymised]',
        ], [
            'Name' => $label,
            'Email' => $email,
        ], (int) $actor['id'], 'privacy');

        // The same person may also be an employee or a user with this email.
        if ($originalEmail === '') {
            return;
        }
        if ($subject['type'] === 'user') {
            $employee = $this->privacy->findEmployeeByEmail($originalEmail);
            if ($employee !== null && empty($employee['anonymised_at'])) {
                $this->anonymise($actor, $this->employeeSubject($employee));
            }
        } else {
            $linkedUser = $this->users->findByEmail($originalEmail);
            if ($linkedUser !== null && empty($linkedUser['anonymised_at'])) {
                $this->anonymise($actor, $this->userSubject($linkedUser));
            }
        }
    }

    /** @param array<string, mixed> $subject @return array<string, mixed> */
    private function exportSubject(array $subject): array
    {
        $linked = null;
        if ($subject['type'] === 'user' && is_string($subject['email']) && $subject['email'] !== '') {
            $employee = $this->privacy->findEmployeeByEmail($subject['email']);
            if ($employee !== null) {
                $linked = $this->publicSubject($this->employeeSubject($employee));
            }
        }
        if ($subject['type'] === 'employee' && is_string($subject['email']) && $subject['email'] !== '') {
            $user = $this->users->findByEmail($subject['email']);
            if ($user !== null) {
                $linked = $this->publicSubject($this->userSubject($user));
            }
        }
        $devices = [];
        if ($subject['type'] === 'user') {
            foreach ((new ApiTokenRepository())->listForUser($subject['id']) as $device) {
                $devices[] = (new ApiTokenService())->present($device);
            }
        }

        return [
            'exportedAt' => UtcTime::iso(UtcTime::now()),
            'subject' => $this->publicSubject($subject),
            'linked' => $linked,
            'consents' => array_map([$this, 'presentConsent'], $this->privacy->consentsFor($subject['type'], $subject['id'], true)),
            'requests' => array_map([$this, 'presentRequest'], $this->privacy->requestsFor($subject['type'], $subject['id'], true)),
            'devices' => $devices,
            'note' => 'Audit log rows are not included. They are kept for the hash chain and are not rewritten.',
        ];
    }

    /** @return array<string, mixed> */
    private function openOrFail(int $id): array
    {
        $request = $this->privacy->findRequest($id) ?? throw HttpException::notFound('Privacy request not found.');
        if ($request['status'] !== 'open') {
            throw new HttpException(409, 'request_closed', 'This request is already closed.');
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private function requireSubject(array $input, array &$errors): array
    {
        $type = $input['subjectType'] ?? null;
        if (!is_string($type) || !in_array($type, ['user', 'employee'], true)) {
            $errors['subjectType'] = 'Choose a user or an employee.';
            $type = 'user';
        }
        $id = $input['subjectId'] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            $errors['subjectId'] = 'Enter the person’s id.';

            return ['type' => $type, 'id' => 0, 'name' => '', 'email' => null, 'phone' => null, 'anonymised' => false];
        }
        if ($errors !== []) {
            return ['type' => (string) $type, 'id' => (int) $id, 'name' => '', 'email' => null, 'phone' => null, 'anonymised' => false];
        }

        try {
            return $this->loadSubject((string) $type, (int) $id);
        } catch (HttpException $e) {
            if ($e->status === 404) {
                $errors['subjectId'] = 'That person was not found.';

                return ['type' => (string) $type, 'id' => (int) $id, 'name' => '', 'email' => null, 'phone' => null, 'anonymised' => false];
            }
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function loadSubject(string $type, int $id): array
    {
        $type = $this->subjectType($type);
        if ($type === 'user') {
            $user = $this->users->find($id) ?? throw HttpException::notFound('User not found.');

            return $this->userSubject($user);
        }
        $employee = $this->privacy->findEmployee($id) ?? throw HttpException::notFound('Employee not found.');

        return $this->employeeSubject($employee);
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    private function userSubject(array $user): array
    {
        return [
            'type' => 'user',
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => $user['email'],
            'phone' => null,
            'status' => $user['status'] ?? null,
            'role' => $user['role_name'] ?? ($user['role']['name'] ?? null),
            'anonymised' => !empty($user['anonymised_at']),
            'createdAt' => $user['created_at'] ?? null,
            'updatedAt' => $user['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $employee @return array<string, mixed> */
    private function employeeSubject(array $employee): array
    {
        return [
            'type' => 'employee',
            'id' => (int) $employee['id'],
            'name' => (string) $employee['name'],
            'email' => $employee['email'],
            'phone' => $employee['phone'],
            'status' => $employee['status'] ?? null,
            'role' => $employee['role'] ?? null,
            'anonymised' => !empty($employee['anonymised_at']),
            'createdAt' => $employee['created_at'] ?? null,
            'updatedAt' => $employee['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $subject @return array<string, mixed> */
    private function publicSubject(array $subject): array
    {
        return [
            'type' => $subject['type'],
            'id' => $subject['id'],
            'name' => $subject['name'],
            'email' => $subject['email'],
            'phone' => $subject['phone'],
            'status' => $subject['status'],
            'role' => $subject['role'],
            'anonymised' => $subject['anonymised'],
        ];
    }

    private function subjectType(string $type): string
    {
        if (!in_array($type, ['user', 'employee'], true)) {
            throw HttpException::validation(['subjectType' => 'Choose a user or an employee.']);
        }

        return $type;
    }

    /**
     * @param list<string> $allowed
     * @param array<string, string> $errors
     */
    private function oneOf(mixed $value, array $allowed, string $field, string $message, array &$errors): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $errors[$field] = $message;

            return $allowed[0];
        }

        return $value;
    }

    /** @param array<string, string> $errors */
    private function shortText(mixed $value, string $field, int $max, bool $required, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                $errors[$field] = 'This field is required.';
            }

            return null;
        }
        if (!is_string($value)) {
            $errors[$field] = 'Enter text.';

            return null;
        }
        $text = trim($value);
        if ($text === '') {
            if ($required) {
                $errors[$field] = 'This field is required.';
            }

            return null;
        }
        if (mb_strlen($text) > $max) {
            $errors[$field] = "Use {$max} characters or fewer.";

            return null;
        }

        return $text;
    }

    /** @param array<string, mixed> $row */
    private function presentConsent(array $row): array
    {
        if ($row === []) {
            return [];
        }
        $subject = $this->safeSubject((string) $row['subject_type'], (int) $row['subject_id']);

        return [
            'id' => (int) $row['id'],
            'subjectType' => $row['subject_type'],
            'subjectId' => (int) $row['subject_id'],
            'subjectName' => $subject['name'],
            'purpose' => $row['purpose'],
            'status' => $row['status'],
            'noticeVersion' => $row['notice_version'],
            'channel' => $row['channel'],
            'notes' => $row['notes'],
            'recordedAt' => UtcTime::iso($row['recorded_at'] ?? null),
            'withdrawnAt' => UtcTime::iso(isset($row['withdrawn_at']) ? (string) $row['withdrawn_at'] : null),
            'archived' => !empty($row['archived_at']),
        ];
    }

    /** @param array<string, mixed> $row */
    private function presentRequest(array $row): array
    {
        if ($row === []) {
            return [];
        }
        $subject = $this->safeSubject((string) $row['subject_type'], (int) $row['subject_id']);

        return [
            'id' => (int) $row['id'],
            'kind' => $row['kind'],
            'subjectType' => $row['subject_type'],
            'subjectId' => (int) $row['subject_id'],
            'subjectName' => $subject['name'],
            'status' => $row['status'],
            'detail' => $row['detail'],
            'outcome' => $row['outcome'],
            'requestedAt' => UtcTime::iso($row['requested_at'] ?? null),
            'fulfilledAt' => UtcTime::iso(isset($row['fulfilled_at']) ? (string) $row['fulfilled_at'] : null),
            'archived' => !empty($row['archived_at']),
        ];
    }

    /** @return array{name: string} */
    private function safeSubject(string $type, int $id): array
    {
        try {
            $subject = $this->loadSubject($type, $id);

            return ['name' => (string) $subject['name']];
        } catch (HttpException) {
            return ['name' => 'Unknown person'];
        }
    }

    /** @param array<string, mixed> $subject @param array<string, mixed> $details */
    private function audit(string $action, array $subject, array $details): void
    {
        AuditLogger::record([
            'module' => 'privacy',
            'action' => $action,
            'targetType' => (string) $subject['type'],
            'targetId' => (int) $subject['id'],
            'targetLabel' => (string) $subject['name'],
            'details' => $details,
        ]);
    }
}
