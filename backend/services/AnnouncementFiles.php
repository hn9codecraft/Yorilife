<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnnouncementRepository;
use App\Utils\Env;
use App\Utils\HttpException;

/**
 * Banner image and attachments. Files live outside the public folder
 * (storage/announcements/{id}/) under random names and are only served
 * through the API after a permission check. Checked by content, not name.
 */
final class AnnouncementFiles
{
    public const MAX_FILES = 5;
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(private readonly AnnouncementRepository $repository = new AnnouncementRepository())
    {
    }

    public static function storageRoot(): string
    {
        $configured = Env::get('ANNOUNCEMENT_STORAGE_PATH', '');
        $root = is_string($configured) && $configured !== '' ? $configured : dirname(__DIR__) . '/storage/announcements';

        return rtrim(str_replace('\\', '/', $root), '/');
    }

    /** @param array<string, mixed>|null $upload one entry of $_FILES */
    public function upload(int $id, mixed $kind, ?array $upload, array $user): array
    {
        $announcement = $this->repository->find($id) ?? throw HttpException::notFound('Announcement not found.');
        if (!in_array($announcement['status'], ['draft', 'scheduled', 'published'], true)) {
            throw new HttpException(409, 'not_editable', 'Files cannot be changed on an expired or recalled announcement.');
        }
        if ($announcement['status'] === 'published') {
            Authorization::authorize($user, 'announcements.publish');
        }
        if (!in_array($kind, ['banner', 'file'], true)) {
            throw HttpException::validation(['kind' => 'Choose banner or attachment.']);
        }
        $content = $this->read($upload);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';
        if (!isset(self::TYPES[$mime]) || ($kind === 'banner' && $mime === 'application/pdf')) {
            throw HttpException::validation(['file' => $kind === 'banner' ? 'The banner must be a JPG or PNG image.' : 'Only PDF, JPG and PNG files can be attached.']);
        }
        $existing = $this->repository->attachments($id);
        if ($kind === 'file' && count(array_filter($existing, static fn (array $a): bool => $a['kind'] === 'file')) >= self::MAX_FILES) {
            throw HttpException::validation(['file' => 'An announcement can have at most ' . self::MAX_FILES . ' attachments. Remove one first.']);
        }

        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        $directory = self::storageRoot() . '/' . $id;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new HttpException(500, 'storage_unavailable', 'The file could not be saved. Try again.');
        }
        if (file_put_contents($directory . '/' . $name, $content) === false) {
            throw new HttpException(500, 'storage_unavailable', 'The file could not be saved. Try again.');
        }
        $original = $this->cleanName((string) ($upload['name'] ?? 'file'), self::TYPES[$mime]);
        $now = UtcTime::now();
        $attachmentId = $this->repository->transaction(function () use ($id, $kind, $original, $name, $mime, $content, $user, $now, $existing): int {
            if ($kind === 'banner') {
                foreach ($existing as $old) {
                    if ($old['kind'] === 'banner') {
                        $this->repository->removeAttachment((int) $old['id'], $now);
                    }
                }
            }
            $attachmentId = $this->repository->insertAttachment([
                'announcement_id' => $id,
                'kind' => $kind,
                'original_name' => $original,
                'stored_path' => $id . '/' . $name,
                'mime' => $mime,
                'size_bytes' => strlen($content),
                'sha256' => hash('sha256', $content),
                'uploaded_by' => (int) $user['id'],
                'created_at' => $now,
            ]);
            $this->repository->update($id, ['banner_attachment_id' => $kind === 'banner' ? $attachmentId : $this->bannerId($id), 'updated_by' => (int) $user['id'], 'updated_at' => $now]);
            $this->repository->logChange($id, 'attachment_added', ['kind' => $kind, 'name' => $original], (int) $user['id'], $now);

            return $attachmentId;
        });

        return AnnouncementService::presentAttachment($this->repository->attachment($id, $attachmentId));
    }

    public function remove(int $id, int $attachmentId, array $user): void
    {
        $announcement = $this->repository->find($id) ?? throw HttpException::notFound('Announcement not found.');
        if (!in_array($announcement['status'], ['draft', 'scheduled', 'published'], true)) {
            throw new HttpException(409, 'not_editable', 'Files cannot be changed on an expired or recalled announcement.');
        }
        if ($announcement['status'] === 'published') {
            Authorization::authorize($user, 'announcements.publish');
        }
        $file = $this->repository->attachment($id, $attachmentId) ?? throw HttpException::notFound('File not found.');
        $now = UtcTime::now();
        $this->repository->transaction(function () use ($id, $file, $user, $now): void {
            $this->repository->removeAttachment((int) $file['id'], $now);
            $this->repository->update($id, ['banner_attachment_id' => $this->bannerId($id), 'updated_by' => (int) $user['id'], 'updated_at' => $now]);
            $this->repository->logChange($id, 'attachment_removed', ['kind' => $file['kind'], 'name' => $file['original_name']], (int) $user['id'], $now);
        });
    }

    /** @return array{filename: string, content: string, mime: string} */
    public function download(int $id, int $attachmentId): array
    {
        $this->repository->find($id) ?? throw HttpException::notFound('Announcement not found.');
        $file = $this->repository->attachment($id, $attachmentId) ?? throw HttpException::notFound('File not found.');
        $path = self::storageRoot() . '/' . $file['stored_path'];
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw HttpException::notFound('The file is no longer stored.');
        }
        if (!hash_equals((string) $file['sha256'], hash('sha256', $content))) {
            throw new HttpException(409, 'file_integrity_failed', 'The stored file does not match its checksum.');
        }

        return ['filename' => (string) $file['original_name'], 'content' => $content, 'mime' => (string) $file['mime']];
    }

    private function bannerId(int $id): ?int
    {
        foreach ($this->repository->attachments($id) as $file) {
            if ($file['kind'] === 'banner') {
                return (int) $file['id'];
            }
        }

        return null;
    }

    private function read(?array $upload): string
    {
        if ($upload === null || !isset($upload['error']) || is_array($upload['error'])) {
            throw HttpException::validation(['file' => 'Choose a file.']);
        }
        $error = (int) $upload['error'];
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw HttpException::validation(['file' => 'The file is larger than 5 MB.']);
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw HttpException::validation(['file' => 'Choose a file.']);
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
            throw HttpException::validation(['file' => 'The file could not be uploaded. Try again.']);
        }
        $size = (int) $upload['size'];
        if ($size <= 0) {
            throw HttpException::validation(['file' => 'The file is empty.']);
        }
        if ($size > self::MAX_BYTES) {
            throw HttpException::validation(['file' => 'The file is larger than 5 MB.']);
        }
        $content = file_get_contents((string) $upload['tmp_name']);
        if ($content === false) {
            throw HttpException::validation(['file' => 'The file could not be read.']);
        }

        return $content;
    }

    private function cleanName(string $name, string $extension): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^\pL\pN ._()-]+/u', '_', $base) ?? 'file';
        $base = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $base) ?? $base;
        $base = mb_substr(trim($base) === '' ? 'file' : trim($base), 0, 180);

        return $base . '.' . $extension;
    }
}