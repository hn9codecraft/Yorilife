<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ImportRollbackService;
use App\Services\ImportService;
use App\Services\ImportTypes;
use App\Services\Input;
use App\Utils\HttpException;
use App\Utils\JsonResponse;
use App\Utils\Request;

/** Excel data import, import history, downloads and rollback (Super Admin and Admin). */
final class ImportController
{
    public function types(Request $request): void
    {
        JsonResponse::success([
            'maxBytes' => ImportService::MAX_BYTES,
            'unified' => ImportTypes::unified() + ['maxRows' => ImportService::MAX_UNIFIED_ROWS],
        ]);
    }

    public function template(Request $request): void
    {
        $file = (new ImportService())->template();
        self::download($file, $file['mime']);
    }

    public function index(Request $request): void
    {
        JsonResponse::success(['imports' => (new ImportService())->list()]);
    }

    public function store(Request $request): void
    {
        $file = $_FILES['file'] ?? null;
        $import = (new ImportService())->upload($_POST['type'] ?? null, is_array($file) ? $file : null, $request->user, $request->ip());
        JsonResponse::success(['import' => $import], 201);
    }

    public function show(Request $request): void
    {
        JsonResponse::success(['import' => (new ImportService())->detail(self::id($request))]);
    }

    public function confirm(Request $request): void
    {
        JsonResponse::success(['import' => (new ImportService())->confirm(self::id($request), $request->user, $request->ip())]);
    }

    public function file(Request $request): void
    {
        $file = (new ImportService())->originalFile(self::id($request), $request->user, $request->ip());
        self::download($file, $file['mime'] ?? 'text/csv; charset=utf-8');
    }

    public function errors(Request $request): void
    {
        self::download((new ImportService())->errorsFile(self::id($request), $request->user, $request->ip()), 'text/csv; charset=utf-8');
    }

    public function backup(Request $request): void
    {
        self::download((new ImportService())->backupFile(self::id($request), $request->user, $request->ip()), 'text/csv; charset=utf-8');
    }

    public function rollbackPreview(Request $request): void
    {
        JsonResponse::success(['rollback' => (new ImportRollbackService())->preview(self::id($request), self::changeIds($request))]);
    }

    public function rollback(Request $request): void
    {
        $id = self::id($request);
        $result = (new ImportRollbackService())->rollback($id, $request->user, $request->ip(), self::changeIds($request));
        JsonResponse::success(['rollback' => $result, 'import' => (new ImportService())->detail($id)]);
    }

    /** Optional "changeIds" (the preview items to roll back); absent means all of them. */
    private static function changeIds(Request $request): ?array
    {
        $body = $request->json();
        if (!array_key_exists('changeIds', $body)) {
            return null;
        }
        if (!is_array($body['changeIds'])) {
            throw HttpException::validation(['changeIds' => 'Choose the records to roll back.']);
        }

        return array_values($body['changeIds']);
    }

    private static function id(Request $request): int
    {
        return Input::pathId($request->params, 'Import not found.');
    }

    /** @param array{filename: string, content: string} $file */
    private static function download(array $file, string $type): void
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['filename']) ?: 'download';
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $type);
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . strlen($file['content']));
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        echo $file['content'];
    }
}
