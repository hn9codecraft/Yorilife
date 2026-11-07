<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RetentionEnforcer;
use App\Utils\JsonResponse;
use App\Utils\Request;

/** Apply the configured retention periods. Archives rows. Never deletes them. */
final class RetentionController
{
    public function apply(Request $request): void
    {
        JsonResponse::success((new RetentionEnforcer())->apply($request->user));
    }
}
