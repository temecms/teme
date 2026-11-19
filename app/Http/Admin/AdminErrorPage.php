<?php

/**
 * A refused or missing admin page drawn inside the admin, its menu and top
 * bar kept, for someone signed in who opens it as a page; anything else is
 * left to the standalone card (resources/views/errors).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Users\Queries\CurrentUserData;
use App\Http\Standalone\StandalonePage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class AdminErrorPage
{
    /**
     * The page for a 403 or 404 under /admin, opened as a page by someone
     * signed in; null for any other error or request, which the standalone
     * card answers.
     *
     * @since next
     */
    public static function render(Throwable $exception, Request $request): ?Response
    {
        $status = match (true) {
            $exception instanceof AuthorizationException => 403,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => null,
        };

        if (! in_array($status, [403, 404], true)
            || ! $request->is('admin', 'admin/*')
            || ! $request->isMethod('GET')
            || $request->expectsJson()
            || ! $request->hasSession()
            || $request->user() === null) {
            return null;
        }

        $user = app(CurrentUserData::class)->find($request->user()->getAuthIdentifier());
        $site = app(StandalonePage::class)->brand()['name'];
        $reason = $status === 403 ? self::reason($exception) : null;

        return Inertia::render('errors/in-admin', [
            'status' => $status,
            'site' => $site,
            'role' => $user['role'] ?? null,
            'reason' => $reason,
        ])->withViewData(['fallback' => self::words($status, $site, $reason)])
            ->toResponse($request)
            ->setStatusCode($status);
    }

    /**
     * The page's title and what happened, in the in-admin page's own words,
     * which the root view also draws for a browser that runs no script.
     *
     * @return array{title: string, text: string}
     */
    private static function words(int $status, ?string $site, ?string $reason): array
    {
        if ($status === 404) {
            return [
                'title' => __('We can’t find that page'),
                'text' => __('The address may be mistyped, or the item may have moved to the Trash. Search the admin to find it.'),
            ];
        }

        return [
            'title' => __('You can’t open this page'),
            'text' => $reason ?? ($site === null
                ? __('Your account doesn’t have permission to do that. If you need it, ask an administrator to change your role.')
                : __('Your account doesn’t have permission to do that. If you need it, ask an administrator of :site to change your role.', ['site' => $site])),
        ];
    }

    /**
     * Answers an admin address no route has, within the admin's own
     * middleware, so the 404 knows who is signed in and is drawn inside the
     * admin.
     *
     * @since next
     */
    public function notFound(): never
    {
        abort(404);
    }

    /** A policy's own reason for refusing, or null for the general words. */
    private static function reason(Throwable $exception): ?string
    {
        $reason = trim($exception->getMessage());

        return in_array($reason, ['', 'This action is unauthorized.', __('This action is unauthorized.')], true) ? null : $reason;
    }
}
