<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PrivacyService;
use App\Utils\HttpException;
use App\Utils\JsonResponse;
use App\Utils\Request;

/** DPDP consent, export, correction tracking and in-place anonymising. */
final class PrivacyController
{
    public function consents(Request $request): void
    {
        JsonResponse::success((new PrivacyService())->listConsents($request->user, $_GET));
    }

    public function storeConsent(Request $request): void
    {
        JsonResponse::success((new PrivacyService())->recordConsent($request->user, $request->json()), 201);
    }

    public function withdrawConsent(Request $request): void
    {
        JsonResponse::success((new PrivacyService())->withdrawConsent($request->user, self::id($request, 'Consent record not found.')));
    }

    public function requests(Request $request): void
    {
        JsonResponse::success((new PrivacyService())->listRequests($request->user, $_GET));
    }

    public function storeRequest(Request $request): void
    {
        JsonResponse::success((new PrivacyService())->openRequest($request->user, $request->json()), 201);
    }

    public function fulfill(Request $request): void
    {
        JsonResponse::success((new PrivacyService())->fulfill($request->user, self::id($request, 'Privacy request not found.'), $request->json()));
    }

    public function reject(Request $request): void
    {
        JsonResponse::success((new PrivacyService())->reject($request->user, self::id($request, 'Privacy request not found.'), $request->json()));
    }

    private static function id(Request $request, string $message): int
    {
        $id = $request->params['id'] ?? '';
        if (!ctype_digit($id) || (int) $id < 1) {
            throw HttpException::notFound($message);
        }

        return (int) $id;
    }
}
