<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnnouncementRepository;
use App\Utils\HttpException;

/** Saved starting points for announcements ("Use template" opens a prefilled draft). */
final class AnnouncementTemplateService
{
    public function __construct(private readonly AnnouncementRepository $repository = new AnnouncementRepository())
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map([self::class, 'present'], $this->repository->templates());
    }

    public function create(array $input, array $user): array
    {
        $values = $this->validate($input);
        $now = UtcTime::now();
        $id = $this->repository->insertTemplate($values + ['created_by' => (int) $user['id'], 'updated_by' => (int) $user['id'], 'created_at' => $now, 'updated_at' => $now]);

        return self::present($this->repository->template($id));
    }

    public function update(int $id, array $input, array $user): array
    {
        $this->repository->template($id) ?? throw HttpException::notFound('Template not found.');
        $values = $this->validate($input);
        $this->repository->updateTemplate($id, $values + ['updated_by' => (int) $user['id'], 'updated_at' => UtcTime::now()]);

        return self::present($this->repository->template($id));
    }

    public function delete(int $id, array $user): void
    {
        $this->repository->template($id) ?? throw HttpException::notFound('Template not found.');
        $now = UtcTime::now();
        $this->repository->updateTemplate($id, ['deleted_at' => $now, 'updated_by' => (int) $user['id'], 'updated_at' => $now]);
    }

    private function validate(array $input): array
    {
        $errors = [];
        Input::onlyFields($input, ['name', 'type', 'priority', 'title', 'bodyHtml', 'requiresAck'], $errors);
        $name = Input::text($input, 'name', 'template name', 100, $errors);
        $title = Input::text($input, 'title', 'title', AnnouncementService::TITLE_MAX, $errors);
        $type = $input['type'] ?? 'general';
        if (!is_string($type) || !isset(AnnouncementService::TYPES[$type])) {
            $errors['type'] = 'Choose a type.';
        }
        $priority = $input['priority'] ?? 'normal';
        if (!is_string($priority) || !isset(AnnouncementService::PRIORITIES[$priority])) {
            $errors['priority'] = 'Choose a priority.';
        }
        $body = $input['bodyHtml'] ?? '';
        if (!is_string($body) || mb_strlen($body) > AnnouncementService::BODY_MAX) {
            $errors['bodyHtml'] = 'The message is too long.';
            $body = '';
        }
        if (array_key_exists('requiresAck', $input) && !is_bool($input['requiresAck'])) {
            $errors['requiresAck'] = 'Choose yes or no.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $html = HtmlSanitizer::clean($body);

        return [
            'name' => $name,
            'title' => $title,
            'type' => $type,
            'priority' => $priority,
            'body_html' => HtmlSanitizer::text($html) === '' ? '' : $html,
            'requires_ack' => ($input['requiresAck'] ?? false) === true ? 1 : 0,
        ];
    }

    /** @return array<string, mixed> */
    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'typeLabel' => AnnouncementService::TYPES[$row['type']] ?? $row['type'],
            'priority' => $row['priority'],
            'priorityLabel' => AnnouncementService::PRIORITIES[$row['priority']] ?? $row['priority'],
            'title' => $row['title'],
            'bodyHtml' => $row['body_html'],
            'requiresAck' => (bool) $row['requires_ack'],
            'createdBy' => $row['created_by_name'] ?? null,
            'updatedAt' => UtcTime::iso($row['updated_at']),
        ];
    }
}