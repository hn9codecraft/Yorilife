<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Authorization;
use App\Services\ExportAudit;
use App\Services\UserValidator;
use App\Utils\HttpException;
use App\Utils\JsonResponse;
use App\Utils\Request;

/** Records a CSV export that was built in the browser (the file is not uploaded). */
final class ExportController
{
    /** POST /api/exports { kind, filename, rowCount } */
    public function store(Request $request): void
    {
        $input = $request->json();
        $errors = [];
        UserValidator::onlyFields($input, ['kind', 'filename', 'rowCount'], $errors);
        $kind = $input['kind'] ?? null;
        if ($kind !== 'sales_office_csv') {
            $errors['kind'] = 'Choose a known export.';
        }
        $filename = is_string($input['filename'] ?? null) ? basename($input['filename']) : '';
        if ($filename === '' || strlen($filename) > 120 || preg_match('/^[A-Za-z0-9._-]+\.csv$/', $filename) !== 1) {
            $errors['filename'] = 'Enter a .csv file name.';
        }
        $count = $input['rowCount'] ?? null;
        if (!is_int($count) && !(is_string($count) && ctype_digit($count))) {
            $errors['rowCount'] = 'Enter how many rows were exported.';
        } else {
            $count = (int) $count;
            if ($count < 0 || $count > 100000) {
                $errors['rowCount'] = 'Row count is out of range.';
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($kind === 'sales_office_csv') {
            Authorization::authorize($request->user, 'sales_office.view');
        }
        ExportAudit::record((string) $kind, $filename, (int) $count);
        JsonResponse::success(['recorded' => true]);
    }
}
