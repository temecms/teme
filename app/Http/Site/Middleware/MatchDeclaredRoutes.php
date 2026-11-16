<?php

declare(strict_types=1);

namespace App\Http\Site\Middleware;

use App\Domain\Cache\PageCache;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\PublicRoutes\RouteDispatcher;
use App\Http\Site\PublicPage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Checks declared routes in front of the public site's own routes: whatever
 * entry the registry matches, conditional or not, answers; only when none
 * matches does the request pass through unchanged.
 */
final class MatchDeclaredRoutes
{
    public function __construct(
        private PublicRouteRegistry $registry,
        private RouteDispatcher $dispatcher,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $this->registry->matchRequest($request);

        if ($route === null) {
            return $next($request);
        }

        if ($route->isCacheable()) {
            $request->attributes->set(PageCache::CACHEABLE, true);
        }

        return $this->dispatcher->dispatch($route, $request, fn (): Response => PublicPage::error(new HttpException(500)));
    }
}
