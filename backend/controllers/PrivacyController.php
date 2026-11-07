<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PrivacyService;
use App\Utils\HttpException;
use App\Utils\JsonResponse;
use App\Utils\Request;

final class PrivacyController
{
    /** GET /api/privacy/me — the signed-in person's own data. */
    public function me(Request $request): void
    {
        JsonResponse::success(['export' => (new PrivacyService())->exportSelf($request->user)]);
    }

    /** GET /api/privacy/consents */
    public function consents(Request $request): void
    {
        JsonResponse::success((new PrivacyService())->listConsents($request->user, $_GET));
    }

    /** POST /api/privacy/consents */
    public function storeConsent(Request $request): void
    {
        JsonResponse::success(['consent' => (new PrivacyService())->recordConsent($request->user, $request->json())], 201);
    }

    /** GET /api/privacy/requests */
    public function requests(Request $request): void
    {
        JsonResponse::success((new PrivacyService())->listRequests($request->user, $_GET));
    }

    /** POST /api/privacy/requests */
    public function storeRequest(Request $request): void
    {
        JsonResponse::success(['request' => (new PrivacyService())->openRequest($request->user, $request->json())], 201);
    }

    /** PATCH /api/privacy/requests/{id} */
    public function updateRequest(Request $request): void
    {
        $updated = (new PrivacyService())->updateRequest($request->user, self::id($request), $request->json());

        JsonResponse::success(['request' => $updated]);
    }

    /** POST /api/privacy/requests/{id}/export */
    public function export(Request $request): void
    {
        $request->json();
        JsonResponse::success((new PrivacyService())->fulfillExport($request->user, self::id($request)));
    }

    /** POST /api/privacy/requests/{id}/anonymize */
    public function anonymize(Request $request): void
    {
        $request->json();
        JsonResponse::success(['request' => (new PrivacyService())->fulfillAnonymize($request->user, self::id($request))]);
    }

    private static function id(Request $request): int
    {
        $id = $request->params['id'] ?? '';
        if (!ctype_digit($id) || (int) $id < 1) {
            throw HttpException::notFound('Privacy request not found.');
        }

        return (int) $id;
    }
}
