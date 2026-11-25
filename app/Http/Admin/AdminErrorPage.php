<?php

/**
 * A refused or missing admin page drawn inside the admin, its menu and top
 * bar kept, for someone signed in who opens it as a page; anything else is
 * left to the standalone card (resources/views/errors).
 *
 * @package Teme
 * @since   1.2.0
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
     * @since 1.2.0
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
        $page = $status === 403 ? self::pageName($request) : null;
        $permission = $status === 403 ? self::permissionName($request) : null;

        return Inertia::render('errors/in-admin', [
            'status' => $status,
            'site' => $site,
            'role' => $user['role'] ?? null,
            'reason' => $reason,
            'page' => $page,
            'permission' => $permission,
        ])->withViewData(['fallback' => self::words($status, $site, $reason, $page, $permission)])
            ->toResponse($request)
            ->setStatusCode($status);
    }

    /**
     * The page's title and what happened, in the in-admin page's own words,
     * which the root view also draws for a browser that runs no script.
     *
     * @return array{title: string, text: string}
     */
    private static function words(int $status, ?string $site, ?string $reason, ?string $page = null, ?string $permission = null): array
    {
        if ($status === 404) {
            return [
                'title' => __('We can’t find that page'),
                'text' => __('The address may be mistyped, or the item may have moved to the Trash. Search the admin to find it.'),
            ];
        }

        $title = $page === null
            ? __('You can’t open this page')
            : __('You can’t open :page', ['page' => $page]);

        if ($reason !== null) {
            $text = $reason;
        } elseif ($permission !== null) {
            $text = $site === null
                ? __('This page needs the :permission permission. If you need it, ask an administrator to change your role.', ['permission' => $permission])
                : __('This page needs the :permission permission. If you need it, ask an administrator of :site to change your role.', ['permission' => $permission, 'site' => $site]);
        } else {
            $text = $site === null
                ? __('Your account doesn’t have permission to do that. If you need it, ask an administrator to change your role.')
                : __('Your account doesn’t have permission to do that. If you need it, ask an administrator of :site to change your role.', ['site' => $site]);
        }

        return [
            'title' => $title,
            'text' => $text,
        ];
    }

    /**
     * A short name for the admin page that was refused, from its path.
     */
    private static function pageName(Request $request): ?string
    {
        $path = trim($request->path(), '/');

        if ($path === 'admin' || ! str_starts_with($path, 'admin/')) {
            return null;
        }

        $rest = substr($path, strlen('admin/'));
        $segments = explode('/', $rest);
        $segment = $segments[0];

        if ($segment === '' || str_starts_with($segment, '_')) {
            return null;
        }

        return match ($segment) {
            'settings' => __('Settings'),
            'tools' => __('Tools'),
            'plugins' => __('Plugins'),
            'themes' => __('Themes'),
            'media' => __('Media'),
            'users', 'people' => __('People'),
            'posts' => __('Posts'),
            'pages' => __('Pages'),
            'comments' => __('Comments'),
            'account' => __('Your account'),
            'content' => __('Content'),
            default => str_replace('-', ' ', $segment),
        };
    }

    /**
     * The can: permission on the refused route, when the route names one.
     */
    private static function permissionName(Request $request): ?string
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                continue;
            }

            $ability = substr($middleware, 4);
            $parts = explode(',', $ability);
            $ability = $parts[0];

            return $ability !== '' ? $ability : null;
        }

        return null;
    }

    /**
     * Answers an admin address no route has, within the admin's own
     * middleware, so the 404 knows who is signed in and is drawn inside the
     * admin.
     *
     * @since 1.2.0
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
