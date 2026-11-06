<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AnnouncementFiles;
use App\Services\AnnouncementService;
use App\Services\AnnouncementTemplateService;
use App\Services\Input;
use App\Utils\JsonResponse;
use App\Utils\Request;

/** Announcements admin API (Super Admin and Admin through announcements.* permissions). */
final class AnnouncementController
{
    public function index(Request $request): void
    {
        JsonResponse::success(['announcements' => (new AnnouncementService())->list()]);
    }

    public function options(Request $request): void
    {
        JsonResponse::success((new AnnouncementService())->options());
    }

    public function show(Request $request): void
    {
        JsonResponse::success(['announcement' => (new AnnouncementService())->detail(self::id($request))]);
    }

    public function store(Request $request): void
    {
        JsonResponse::success(['announcement' => (new AnnouncementService())->create($request->json(), $request->user)], 201);
    }

    public function update(Request $request): void
    {
        JsonResponse::success(['announcement' => (new AnnouncementService())->update(self::id($request), $request->json(), $request->user)]);
    }

    public function delete(Request $request): void
    {
        (new AnnouncementService())->delete(self::id($request), $request->user);
        JsonResponse::success(['deleted' => true]);
    }

    public function copy(Request $request): void
    {
        JsonResponse::success(['announcement' => (new AnnouncementService())->copy(self::id($request), $request->user)], 201);
    }

    public function publish(Request $request): void
    {
        JsonResponse::success(['announcement' => (new AnnouncementService())->publish(self::id($request), $request->json(), $request->user)]);
    }

    public function unschedule(Request $request): void
    {
        JsonResponse::success(['announcement' => (new AnnouncementService())->unschedule(self::id($request), $request->user)]);
    }

    public function pin(Request $request): void
    {
        JsonResponse::success(['announcement' => (new AnnouncementService())->pin(self::id($request), $request->json(), $request->user)]);
    }

    public function recall(Request $request): void
    {
        JsonResponse::success(['announcement' => (new AnnouncementService())->recall(self::id($request), $request->json(), $request->user)]);
    }

    public function audiencePreview(Request $request): void
    {
        JsonResponse::success(['audience' => (new AnnouncementService())->audiencePreview($request->json())]);
    }

    public function tracking(Request $request): void
    {
        JsonResponse::success(['tracking' => (new AnnouncementService())->tracking(self::id($request))]);
    }

    public function trackingExport(Request $request): void
    {
        $file = (new AnnouncementService())->trackingCsv(self::id($request), $request->query('pending') === '1');
        self::send($file['filename'], $file['content'], 'text/csv; charset=utf-8', false);
    }

    public function uploadAttachment(Request $request): void
    {
        $file = $_FILES['file'] ?? null;
        $attachment = (new AnnouncementFiles())->upload(self::id($request), $_POST['kind'] ?? 'file', is_array($file) ? $file : null, $request->user);
        JsonResponse::success(['attachment' => $attachment], 201);
    }

    public function downloadAttachment(Request $request): void
    {
        $file = (new AnnouncementFiles())->download(self::id($request), Input::pathId($request->params, 'File not found.', 'attachmentId'));
        $inline = $request->query('inline') === '1' && str_starts_with($file['mime'], 'image/');
        self::send($file['filename'], $file['content'], $file['mime'], $inline);
    }

    public function removeAttachment(Request $request): void
    {
        (new AnnouncementFiles())->remove(self::id($request), Input::pathId($request->params, 'File not found.', 'attachmentId'), $request->user);
        JsonResponse::success(['deleted' => true]);
    }

    // ---- Templates ----------------------------------------------------------

    public function templates(Request $request): void
    {
        JsonResponse::success(['templates' => (new AnnouncementTemplateService())->list()]);
    }

    public function storeTemplate(Request $request): void
    {
        JsonResponse::success(['template' => (new AnnouncementTemplateService())->create($request->json(), $request->user)], 201);
    }

    public function updateTemplate(Request $request): void
    {
        JsonResponse::success(['template' => (new AnnouncementTemplateService())->update(self::id($request, 'Template not found.'), $request->json(), $request->user)]);
    }

    public function deleteTemplate(Request $request): void
    {
        (new AnnouncementTemplateService())->delete(self::id($request, 'Template not found.'), $request->user);
        JsonResponse::success(['deleted' => true]);
    }

    private static function id(Request $request, string $notFound = 'Announcement not found.'): int
    {
        return Input::pathId($request->params, $notFound);
    }

    private static function send(string $filename, string $content, string $type, bool $inline): void
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $type);
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        }
        echo $content;
    }
}