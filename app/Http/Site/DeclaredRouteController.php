<?php

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Cache\PageCache;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\PublicRoutes\RouteDispatcher;
use App\Http\Controllers\Controller;
use App\Http\Site\Feeds\FeedController;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a declared public route looked up from the registry by method,
 * path and condition. The fixed `{code}/{path?}` shape also matches
 * ordinary public addresses, so a GET that matches no entry falls through
 * to the feed handler (which hands non-feeds to PublicController); any other
 * method that matches nothing is answered 405, never with a page, and
 * `/.well-known/{name}` stays a 404.
 */
final class DeclaredRouteController extends Controller
{
    public function __invoke(Request $request, PublicRouteRegistry $registry, RouteDispatcher $dispatcher, FeedController $feeds): Response
    {
        $route = $registry->matchRequest($request);

        if ($route === null) {
            if (str_starts_with($request->getPathInfo(), '/.well-known/')) {
                throw new NotFoundHttpException;
            }

            if (! $request->isMethodSafe()) {
                return new Response('', 405, ['Allow' => 'GET, HEAD']);
            }

            return $feeds($request);
        }

        if ($route->isCacheable()) {
            $request->attributes->set(PageCache::CACHEABLE, true);
        }

        return $dispatcher->dispatch($route, $request, fn (): Response => PublicPage::error(new HttpException(500)));
    }
}
