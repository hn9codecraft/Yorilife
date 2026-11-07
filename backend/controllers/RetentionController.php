<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RetentionEnforcer;
use App\Services\UserValidator;
use App\Utils\HttpException;
use App\Utils\JsonResponse;
use App\Utils\Request;

final class RetentionController
{
    /** POST /api/retention/apply — archive old rows using the saved periods. Nothing is deleted. */
    public function apply(Request $request): void
    {
        $input = $request->json();
        $errors = [];
        UserValidator::onlyFields($input, [], $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        JsonResponse::success(RetentionEnforcer::apply($request->user));
    }
}
