<?php

/**
 * Signing out leaves the admin for its sign-in page in a full page visit.
 * Fortify's own answer redirects to the public site, which is not an
 * Inertia page, so Inertia drew it in a frame over the admin (issue 509).
 *
 * @package Teme
 * @since   1.1.1
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Auth;

use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse;
use Symfony\Component\HttpFoundation\Response;

final class SignOutResponse implements LogoutResponse
{
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : Inertia::location(route('login'));
    }
}
