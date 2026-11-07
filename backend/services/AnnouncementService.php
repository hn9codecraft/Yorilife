<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnnouncementRepository;
use App\Utils\HttpException;

/**
 * Announcements (admin side): drafts, audience, publish / schedule, expiry,
 * pin, recall, change history and the outbox the future employee app reads.
 *
 * Life cycle: draft -> scheduled -> published -> expired, or published -> recalled.
 * Recipients are frozen when the announcement goes live. Nothing is sent from
 * here: outbox rows wait for the employee app sender (later phase).
 */
final class AnnouncementService
{
    public const TYPES = ['general' => 'General', 'hr' => 'HR', 'policy' => 'Policy', 'holiday' => 'Holiday', 'sales' => 'Sales', 'urgent' => 'Urgent'];
    public const PRIORITIES = ['normal' => 'Normal', 'important' => 'Important', 'urgent' => 'Urgent'];
    public const TITLE_MAX = 150;
    public const BODY_MAX = 50000;

    private const FIELDS = ['title', 'bodyHtml', 'type', 'priority', 'requiresAck', 'audienceAll', 'audience', 'expiresAt', 'markUpdated'];
    private const AUDIENCE_KEYS = ['location' => 'location', 'headquarters' => 'headquarters', 'managerLevel' => 'manager_level', 'role' => 'role', 'employee' => 'employee'];

    public function __construct(
        private readonly AnnouncementRepository $repository = new AnnouncementRepository(),
        private readonly AnnouncementAudience $audience = new AnnouncementAudience(),
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $this->tick();

        return array_map(fn (array $row): array => $this->present($row, false), $this->repository->all());
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $this->tick();
        $row = $this->find($id);
        $data = $this->present($row, true);
        $data['attachments'] = array_map([self::class, 'presentAttachment'], $this->repository->attachments($id));
        $data['history'] = array_map(static fn (array $c): array => [
            'id' => (int) $c['id'],
            'action' => $c['action'],
            'changes' => $c['changes'] === null ? null : json_decode((string) $c['changes'], true),
            'at' => UtcTime::iso($c['changed_at']),
            'by' => $c['changed_by_name'] ?? null,
        ], $this->repository->changes($id));
        $data['counts'] = $this->repository->recipientCounts($id);

        return $data;
    }

    /** @return array<string, mixed> */
    public function create(array $input, array $user): array
    {
        $values = $this->validate($input, null);
        $now = UtcTime::now();
        $id = $this->repository->transaction(function () use ($values, $user, $now): int {
            $id = $this->repository->insert([
                'title' => $values['title'],
                'body_html' => $values['body_html'],
                'body_text' => $values['body_text'],
                'type' => $values['type'],
                'priority' => $values['priority'],
                'requires_ack' => $values['requires_ack'],
                'audience_all' => $values['audience_all'],
                'expires_at' => $values['expires_at'],
                'status' => 'draft',
                'created_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->repository->replaceRules($id, $values['rules']);
            $this->repository->logChange($id, 'created', ['title' => $values['title']], (int) $user['id'], $now);

            return $id;
        });

        return $this->detail($id);
    }

    /** @return array<string, mixed> */
    public function update(int $id, array $input, array $user): array
    {
        $current = $this->find($id);
        if (!in_array($current['status'], ['draft', 'scheduled', 'published'], true)) {
            throw new HttpException(409, 'not_editable', 'Expired or recalled announcements cannot be edited. Copy it as a new draft instead.');
        }
        $published = $current['status'] === 'published';
        if ($published) {
            Authorization::authorize($user, 'announcements.publish');
        }
        $values = $this->validate($input, $current);
        $rulesBefore = $this->repository->rules($id);
        if ($published && ((bool) $values['audience_all'] !== (bool) $current['audience_all'] || self::sortedRules($values['rules']) !== self::sortedRules($rulesBefore))) {
            throw HttpException::validation(['audience' => 'The audience cannot change after publishing. Recall it and publish a new one instead.']);
        }
        if ($values['expires_at'] !== null && $values['expires_at'] <= UtcTime::now() && $values['expires_at'] !== $current['expires_at']) {
            throw HttpException::validation(['expiresAt' => 'The expiry time must be in the future.']);
        }
        if ($current['status'] === 'scheduled' && $values['expires_at'] !== null && $values['expires_at'] <= (string) $current['publish_at']) {
            throw HttpException::validation(['expiresAt' => 'The expiry time must be after the publish time.']);
        }

        $changes = $this->diff($current, $values, $rulesBefore);
        $markUpdated = $published && ($input['markUpdated'] ?? false) === true && $changes !== [];
        $now = UtcTime::now();
        $this->repository->transaction(function () use ($id, $values, $user, $now, $changes, $published, $markUpdated, $current): void {
            $fields = [
                'title' => $values['title'],
                'body_html' => $values['body_html'],
                'body_text' => $values['body_text'],
                'type' => $values['type'],
                'priority' => $values['priority'],
                'requires_ack' => $values['requires_ack'],
                'audience_all' => $values['audience_all'],
                'expires_at' => $values['expires_at'],
                'updated_by' => (int) $user['id'],
                'updated_at' => $now,
            ];
            if ($published && $changes !== []) {
                $fields['version'] = (int) $current['version'] + 1;
            }
            if ($markUpdated) {
                $fields['is_updated'] = 1;
            }
            $this->repository->update($id, $fields);
            if (!$published) {
                $this->repository->replaceRules($id, $values['rules']);
            }
            if ($changes !== []) {
                $this->repository->logChange($id, 'updated', $changes + ($markUpdated ? ['markedUpdated' => true] : []), (int) $user['id'], $now);
            }
            if ($markUpdated) {
                $this->repository->queueOutbox($id, 'announcement.updated', $this->payload($id), $now);
            }
        });

        return $this->detail($id);
    }

    public function delete(int $id, array $user): void
    {
        $current = $this->find($id);
        if ($current['status'] !== 'draft') {
            throw new HttpException(409, 'not_draft', 'Only drafts can be deleted.');
        }
        $now = UtcTime::now();
        $this->repository->transaction(function () use ($id, $user, $now): void {
            $this->repository->update($id, ['deleted_at' => $now, 'updated_by' => (int) $user['id'], 'updated_at' => $now]);
            $this->repository->logChange($id, 'deleted', null, (int) $user['id'], $now);
        });
    }

    /** "Copy as new": a fresh draft with the same content and audience (no files). */
    public function copy(int $id, array $user): array
    {
        $current = $this->find($id);
        $rules = $this->repository->rules($id);
        $now = UtcTime::now();
        $newId = $this->repository->transaction(function () use ($current, $rules, $user, $now, $id): int {
            $newId = $this->repository->insert([
                'title' => mb_substr((string) $current['title'], 0, self::TITLE_MAX),
                'body_html' => $current['body_html'],
                'body_text' => $current['body_text'],
                'type' => $current['type'],
                'priority' => $current['priority'],
                'requires_ack' => (int) $current['requires_ack'],
                'audience_all' => (int) $current['audience_all'],
                'status' => 'draft',
                'created_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->repository->replaceRules($newId, $rules);
            $this->repository->logChange($newId, 'created', ['copiedFrom' => self::reference($id, (string) $current['created_at'])], (int) $user['id'], $now);

            return $newId;
        });

        return $this->detail($newId);
    }

    /** Publish now, or schedule when publishAt is in the future (India time accepted). */
    public function publish(int $id, array $input, array $user): array
    {
        $errors = [];
        Input::onlyFields($input, ['publishAt'], $errors);
        $current = $this->find($id);
        if (!in_array($current['status'], ['draft', 'scheduled'], true)) {
            throw new HttpException(409, 'not_publishable', 'Only drafts and scheduled announcements can be published.');
        }
        $now = UtcTime::now();
        $publishAt = null;
        if (($input['publishAt'] ?? null) !== null && $input['publishAt'] !== '') {
            $publishAt = UtcTime::parse($input['publishAt']);
            if ($publishAt === null) {
                $errors['publishAt'] = 'Enter a valid date and time.';
            } elseif ($publishAt <= $now) {
                $publishAt = null; // a time already passed means "now"
            }
        }
        if (trim((string) $current['body_text']) === '') {
            $errors['bodyHtml'] = 'Write the message before publishing.';
        }
        $goLive = $publishAt ?? $now;
        if ($current['expires_at'] !== null && (string) $current['expires_at'] <= $goLive) {
            $errors['expiresAt'] = 'The expiry time must be after the publish time.';
        }
        $count = count($this->audience->resolve((bool) $current['audience_all'], $this->repository->rules($id)));
        if ($count === 0) {
            $errors['audience'] = 'Nobody matches this audience. Choose who should receive it.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors, 'This announcement is not ready to publish.');
        }

        if ($publishAt !== null) {
            $this->repository->transaction(function () use ($id, $publishAt, $user, $now): void {
                $this->repository->update($id, ['status' => 'scheduled', 'publish_at' => $publishAt, 'published_by' => (int) $user['id'], 'updated_by' => (int) $user['id'], 'updated_at' => $now]);
                $this->repository->logChange($id, 'scheduled', ['publishAt' => UtcTime::iso($publishAt)], (int) $user['id'], $now);
            });
        } else {
            $this->goLive($id, (int) $user['id'], $now);
        }

        return $this->detail($id);
    }

    public function unschedule(int $id, array $user): array
    {
        $current = $this->find($id);
        if ($current['status'] !== 'scheduled') {
            throw new HttpException(409, 'not_scheduled', 'This announcement is not scheduled.');
        }
        $now = UtcTime::now();
        $this->repository->transaction(function () use ($id, $user, $now, $current): void {
            $this->repository->update($id, ['status' => 'draft', 'publish_at' => null, 'published_by' => null, 'updated_by' => (int) $user['id'], 'updated_at' => $now]);
            $this->repository->logChange($id, 'unscheduled', ['publishAt' => UtcTime::iso($current['publish_at'])], (int) $user['id'], $now);
        });

        return $this->detail($id);
    }

    public function pin(int $id, array $input, array $user): array
    {
        $errors = [];
        Input::onlyFields($input, ['pinned'], $errors);
        if (!is_bool($input['pinned'] ?? null)) {
            $errors['pinned'] = 'Choose pin or unpin.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $current = $this->find($id);
        if (!in_array($current['status'], ['draft', 'scheduled', 'published'], true)) {
            throw new HttpException(409, 'not_pinnable', 'Expired or recalled announcements cannot be pinned.');
        }
        $pinned = $input['pinned'];
        if ((bool) $current['is_pinned'] !== $pinned) {
            $now = UtcTime::now();
            $this->repository->transaction(function () use ($id, $pinned, $user, $now): void {
                $this->repository->update($id, ['is_pinned' => $pinned ? 1 : 0, 'updated_by' => (int) $user['id'], 'updated_at' => $now]);
                $this->repository->logChange($id, $pinned ? 'pinned' : 'unpinned', null, (int) $user['id'], $now);
            });
        }

        return $this->detail($id);
    }

    public function recall(int $id, array $input, array $user): array
    {
        $errors = [];
        Input::onlyFields($input, ['reason'], $errors);
        $reason = Input::text($input, 'reason', 'reason for the recall', 500, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $current = $this->find($id);
        if ($current['status'] !== 'published') {
            throw new HttpException(409, 'not_published', 'Only published announcements can be recalled.');
        }
        $now = UtcTime::now();
        $this->repository->transaction(function () use ($id, $reason, $user, $now): void {
            $this->repository->update($id, ['status' => 'recalled', 'recalled_at' => $now, 'recall_reason' => $reason, 'recalled_by' => (int) $user['id'], 'is_pinned' => 0, 'updated_by' => (int) $user['id'], 'updated_at' => $now]);
            $this->repository->logChange($id, 'recalled', ['reason' => $reason], (int) $user['id'], $now);
            $this->repository->queueOutbox($id, 'announcement.recalled', $this->payload($id) + ['reason' => $reason], $now);
        });

        return $this->detail($id);
    }

    /**
     * Scheduler step (bin/announcements-tick.php every minute; also run before
     * list / detail as a safety net): publish due scheduled items, expire old ones.
     *
     * @return array{published: list<int>, expired: list<int>}
     */
    public function tick(): array
    {
        $now = UtcTime::now();
        $published = [];
        foreach ($this->repository->dueScheduled($now) as $id) {
            if ($this->goLive($id, null, $now)) {
                $published[] = $id;
            }
        }
        $expired = [];
        foreach ($this->repository->dueExpiry($now) as $id) {
            $done = $this->repository->transaction(function () use ($id, $now): bool {
                $row = $this->repository->lock($id);
                if ($row === null || $row['status'] !== 'published') {
                    return false;
                }
                $this->repository->update($id, ['status' => 'expired', 'is_pinned' => 0]);
                $this->repository->logChange($id, 'expired', null, null, $now);

                return true;
            });
            if ($done) {
                $expired[] = $id;
            }
        }

        return ['published' => $published, 'expired' => $expired];
    }

    /** @return array<string, mixed> */
    public function audiencePreview(array $input): array
    {
        $errors = [];
        Input::onlyFields($input, ['audienceAll', 'audience'], $errors);
        $all = ($input['audienceAll'] ?? false) === true;
        $rules = $this->rules($input['audience'] ?? [], $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $this->audience->preview($all, $rules) + ['summary' => $this->audience->summary($all, $rules)];
    }

    /** @return array<string, mixed> */
    public function tracking(int $id): array
    {
        $current = $this->find($id);
        $recipients = array_map(static fn (array $r): array => [
            'type' => $r['recipient_type'],
            'id' => (int) $r['recipient_id'],
            'name' => $r['recipient_name'],
            'contact' => $r['recipient_contact'],
            'deliveredAt' => UtcTime::iso($r['delivered_at']),
            'readAt' => UtcTime::iso($r['read_at']),
            'acknowledgedAt' => UtcTime::iso($r['acknowledged_at']),
        ], $this->repository->recipients($id));

        return [
            'status' => $current['status'],
            'requiresAck' => (bool) $current['requires_ack'],
            'appConnected' => false,
            'counts' => $this->repository->recipientCounts($id),
            'outbox' => $this->repository->outboxCounts($id),
            'recipients' => $recipients,
        ];
    }

    /** @return array{filename: string, content: string} */
    public function trackingCsv(int $id, bool $pendingOnly): array
    {
        $current = $this->find($id);
        $rows = [];
        foreach ($this->repository->recipients($id) as $r) {
            if ($pendingOnly && $r['read_at'] !== null) {
                continue;
            }
            $rows[] = [
                ['manager' => 'Manager', 'user' => 'ERP user', 'employee' => 'Employee'][$r['recipient_type']] ?? $r['recipient_type'],
                $r['recipient_name'],
                PiiMask::contact((string) $r['recipient_contact']),
                UtcTime::indiaLabel($r['delivered_at']),
                UtcTime::indiaLabel($r['read_at']) ?: 'Pending',
                $current['requires_ack'] ? (UtcTime::indiaLabel($r['acknowledged_at']) ?: 'Pending') : 'Not required',
            ];
        }
        $ref = self::reference($id, (string) $current['created_at']);
        $filename = $ref . ($pendingOnly ? '-not-read' : '-recipients') . '.csv';
        ExportAudit::record('announcement_tracking', $filename, count($rows), ['announcementId' => $id]);

        return [
            'filename' => $filename,
            'content' => "\xEF\xBB\xBF" . ImportCsv::document(['Type', 'Name', 'Email / Phone', 'Delivered (IST)', 'Read (IST)', 'Acknowledged (IST)'], $rows),
        ];
    }

    /** Pickers for the form (locations, headquarters, roles, employees) and limits. */
    public function options(): array
    {
        $roles = array_map(static fn (array $r): array => ['value' => $r['slug'], 'label' => $r['name'] . ' (ERP role)'], $this->repository->roles());
        foreach ((new \App\Repositories\EmployeeRepository())->roles() as $role) {
            $roles[] = ['value' => $role, 'label' => $role . ' (employee role)'];
        }

        return [
            'types' => array_map(static fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(self::TYPES), self::TYPES),
            'priorities' => array_map(static fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(self::PRIORITIES), self::PRIORITIES),
            'locations' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name']], $this->repository->locations()),
            'headquarters' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => trim($r['name'] . ($r['code'] ? " ({$r['code']})" : ''))], $this->repository->headquarters()),
            'managerLevels' => [['id' => 1, 'name' => '1st line managers'], ['id' => 2, 'name' => '2nd line managers'], ['id' => 3, 'name' => '3rd line managers']],
            'roles' => $roles,
            'employees' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name'] . ($r['role'] ? " ({$r['role']})" : '') . ($r['status'] !== 'active' ? ' - inactive' : '')], $this->repository->employeeChoices()),
            'limits' => [
                'titleMax' => self::TITLE_MAX,
                'maxFiles' => AnnouncementFiles::MAX_FILES,
                'maxBytes' => AnnouncementFiles::MAX_BYTES,
                'fileTypes' => ['PDF', 'JPG', 'PNG'],
            ],
            'timezone' => UtcTime::INDIA,
        ];
    }

    // ---- Internals ----------------------------------------------------------

    /** Make a scheduled or draft announcement live: freeze recipients and queue outbox events. */
    private function goLive(int $id, ?int $userId, string $now): bool
    {
        return $this->repository->transaction(function () use ($id, $userId, $now): bool {
            $row = $this->repository->lock($id);
            if ($row === null || !in_array($row['status'], ['draft', 'scheduled'], true)) {
                return false;
            }
            $current = $this->repository->find($id);
            $people = $this->audience->resolve((bool) $current['audience_all'], $this->repository->rules($id));
            $this->repository->freezeRecipients($id, $people, $now);
            $fields = ['status' => 'published', 'published_at' => $now, 'publish_at' => $current['publish_at'] ?? $now, 'recipient_count' => count($people)];
            if ($userId !== null) {
                $fields['published_by'] = $userId;
                $fields['updated_by'] = $userId;
            }
            $this->repository->update($id, $fields);
            $this->repository->logChange($id, 'published', ['recipients' => count($people), 'by' => $userId === null ? 'scheduler' : 'admin'], $userId, $now);
            $this->repository->queueOutbox($id, 'announcement.published', $this->payload($id), $now);

            return true;
        });
    }

    /** Outbox payload (the contract for the future app). */
    private function payload(int $id): array
    {
        $row = $this->repository->find($id);

        return [
            'id' => $id,
            'reference' => self::reference($id, (string) $row['created_at']),
            'title' => $row['title'],
            'type' => $row['type'],
            'priority' => $row['priority'],
            'shortText' => mb_substr((string) $row['body_text'], 0, 140),
            'requiresAck' => (bool) $row['requires_ack'],
            'publishedAt' => UtcTime::iso($row['published_at']),
            'expiresAt' => UtcTime::iso($row['expires_at']),
        ];
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        return $this->repository->find($id) ?? throw HttpException::notFound('Announcement not found.');
    }

    /** @return array<string, mixed> */
    private function validate(array $input, ?array $current): array
    {
        $errors = [];
        Input::onlyFields($input, self::FIELDS, $errors);
        $title = Input::text($input, 'title', 'title', self::TITLE_MAX, $errors);
        $type = $input['type'] ?? 'general';
        if (!is_string($type) || !isset(self::TYPES[$type])) {
            $errors['type'] = 'Choose a type.';
        }
        $priority = $input['priority'] ?? 'normal';
        if (!is_string($priority) || !isset(self::PRIORITIES[$priority])) {
            $errors['priority'] = 'Choose a priority.';
        }
        $html = '';
        $text = '';
        $rawBody = $input['bodyHtml'] ?? '';
        if (!is_string($rawBody)) {
            $errors['bodyHtml'] = 'The message must be text.';
        } elseif (mb_strlen($rawBody) > self::BODY_MAX) {
            $errors['bodyHtml'] = 'The message is too long (limit ' . self::BODY_MAX . ' characters including formatting).';
        } else {
            $html = HtmlSanitizer::clean($rawBody);
            $text = HtmlSanitizer::text($html);
            if ($text === '') {
                $html = '';
            }
        }
        foreach (['requiresAck', 'audienceAll', 'markUpdated'] as $flag) {
            if (array_key_exists($flag, $input) && !is_bool($input[$flag])) {
                $errors[$flag] = 'Choose yes or no.';
            }
        }
        $expires = null;
        if (($input['expiresAt'] ?? null) !== null && $input['expiresAt'] !== '') {
            $expires = UtcTime::parse($input['expiresAt']);
            if ($expires === null) {
                $errors['expiresAt'] = 'Enter a valid date and time.';
            } elseif ($current === null && $expires <= UtcTime::now()) {
                $errors['expiresAt'] = 'The expiry time must be in the future.';
            }
        }
        $rules = $this->rules($input['audience'] ?? [], $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [
            'title' => $title,
            'body_html' => $html,
            'body_text' => $text,
            'type' => $type,
            'priority' => $priority,
            'requires_ack' => ($input['requiresAck'] ?? false) === true ? 1 : 0,
            'audience_all' => ($input['audienceAll'] ?? false) === true ? 1 : 0,
            'expires_at' => $expires,
            'rules' => $rules,
        ];
    }

    /**
     * Audience JSON { location: [ids], headquarters: [ids], managerLevel: [1..3], role: [slugs or names], employee: [ids] }.
     *
     * @return array<string, list<string>>
     */
    private function rules(mixed $audience, array &$errors): array
    {
        if ($audience === null || $audience === []) {
            return [];
        }
        if (!is_array($audience) || array_is_list($audience)) {
            $errors['audience'] = 'The audience is not valid.';

            return [];
        }
        $rules = [];
        foreach ($audience as $key => $values) {
            $type = self::AUDIENCE_KEYS[$key] ?? null;
            if ($type === null) {
                $errors['audience'] = "Unknown audience filter \"{$key}\".";
                continue;
            }
            if ($type === 'role') {
                if (!is_array($values) || !array_is_list($values) || count($values) > 50) {
                    $errors['audience'] = 'Choose roles from the list.';
                    continue;
                }
                $clean = [];
                foreach ($values as $value) {
                    if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 100) {
                        $errors['audience'] = 'Choose roles from the list.';
                        continue 2;
                    }
                    $clean[mb_strtolower(trim($value))] = trim($value);
                }
                $list = array_values($clean);
            } else {
                $ids = Input::idList($values);
                if ($ids === null || ($type === 'manager_level' && array_diff($ids, [1, 2, 3]) !== [])) {
                    $errors['audience'] = 'The audience is not valid.';
                    continue;
                }
                $list = array_map('strval', $ids);
            }
            if ($list !== []) {
                $rules[$type] = $list;
            }
        }

        return $rules;
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function diff(array $current, array $values, array $rulesBefore): array
    {
        $changes = [];
        $pairs = [
            'title' => [$current['title'], $values['title']],
            'type' => [$current['type'], $values['type']],
            'priority' => [$current['priority'], $values['priority']],
            'requiresAck' => [(bool) $current['requires_ack'], (bool) $values['requires_ack']],
            'expiresAt' => [UtcTime::iso($current['expires_at']), UtcTime::iso($values['expires_at'])],
            'audienceAll' => [(bool) $current['audience_all'], (bool) $values['audience_all']],
        ];
        foreach ($pairs as $field => [$from, $to]) {
            if ($from !== $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }
        if ((string) $current['body_html'] !== $values['body_html']) {
            $changes['message'] = ['from' => mb_substr((string) $current['body_text'], 0, 200), 'to' => mb_substr($values['body_text'], 0, 200)];
        }
        if (self::sortedRules($rulesBefore) !== self::sortedRules($values['rules'])) {
            $changes['audience'] = ['from' => $this->audience->summary((bool) $current['audience_all'], $rulesBefore), 'to' => $this->audience->summary((bool) $values['audience_all'], $values['rules'])];
        }

        return $changes;
    }

    private static function sortedRules(array $rules): array
    {
        ksort($rules);
        foreach ($rules as &$values) {
            sort($values);
        }

        return $rules;
    }

    public static function reference(int $id, string $createdAt): string
    {
        return sprintf('ANN-%s-%04d', UtcTime::indiaYear($createdAt), $id);
    }

    /** @return array<string, mixed> */
    private function present(array $row, bool $full): array
    {
        $id = (int) $row['id'];
        $status = (string) $row['status'];
        $rules = $this->repository->rules($id);
        $audience = [];
        foreach (self::AUDIENCE_KEYS as $key => $type) {
            $audience[$key] = $type === 'role' ? ($rules[$type] ?? []) : array_map('intval', $rules[$type] ?? []);
        }
        $data = [
            'id' => $id,
            'reference' => self::reference($id, (string) $row['created_at']),
            'title' => $row['title'],
            'type' => $row['type'],
            'typeLabel' => self::TYPES[$row['type']] ?? $row['type'],
            'priority' => $row['priority'],
            'priorityLabel' => self::PRIORITIES[$row['priority']] ?? $row['priority'],
            'status' => $status,
            'excerpt' => mb_substr((string) $row['body_text'], 0, 160),
            'requiresAck' => (bool) $row['requires_ack'],
            'isPinned' => (bool) $row['is_pinned'],
            'isUpdated' => (bool) $row['is_updated'],
            'audienceAll' => (bool) $row['audience_all'],
            'audience' => $audience,
            'audienceSummary' => $this->audience->summary((bool) $row['audience_all'], $rules),
            'recipientCount' => (int) $row['recipient_count'],
            'publishAt' => UtcTime::iso($row['publish_at']),
            'publishedAt' => UtcTime::iso($row['published_at']),
            'expiresAt' => UtcTime::iso($row['expires_at']),
            'recalledAt' => UtcTime::iso($row['recalled_at']),
            'recallReason' => $row['recall_reason'],
            'version' => (int) $row['version'],
            'bannerId' => null,
            'createdBy' => $row['created_by_name'] ?? null,
            'updatedBy' => $row['updated_by_name'] ?? null,
            'publishedBy' => $row['published_by_name'] ?? null,
            'recalledBy' => $row['recalled_by_name'] ?? null,
            'createdAt' => UtcTime::iso($row['created_at']),
            'updatedAt' => UtcTime::iso($row['updated_at']),
            'can' => [
                'edit' => in_array($status, ['draft', 'scheduled', 'published'], true),
                'delete' => $status === 'draft',
                'publish' => in_array($status, ['draft', 'scheduled'], true),
                'unschedule' => $status === 'scheduled',
                'pin' => in_array($status, ['draft', 'scheduled', 'published'], true),
                'recall' => $status === 'published',
                'copy' => true,
            ],
        ];
        foreach ($this->repository->attachments($id) as $file) {
            if ($file['kind'] === 'banner') {
                $data['bannerId'] = (int) $file['id'];
            }
        }
        if ($full) {
            $data['bodyHtml'] = $row['body_html'];
            $data['bodyText'] = $row['body_text'];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function presentAttachment(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'kind' => $row['kind'],
            'name' => $row['original_name'],
            'mime' => $row['mime'],
            'size' => (int) $row['size_bytes'],
            'uploadedAt' => UtcTime::iso($row['created_at']),
        ];
    }
}