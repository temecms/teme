<?php

declare(strict_types=1);

namespace App\Http\Site\Middleware;

use App\Domain\Cache\PageCache;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\PublicRoutes\RouteDispatcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks conditional declared routes (query or Accept) in front of the home
 * and fallback public routes: a match is answered by the entry's handler;
 * no match passes through unchanged.
 */
final class MatchDeclaredRoutes
{
    public function __construct(
        private PublicRouteRegistry $registry,
        private RouteDispatcher $dispatcher,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $accept = $this->acceptTypes($request);
        $query = $this->stringQuery($request);
        $route = $this->registry->match($request->getMethod(), $request->getPathInfo(), $query, $accept);

        if ($route === null || ! $route->isConditional()) {
            return $next($request);
        }

        if ($route->isCacheable()) {
            $request->attributes->set(PageCache::CACHEABLE, true);
        }

        return $this->dispatcher->dispatch($route, $request);
    }

    /**
     * @return list<string>
     */
    private function acceptTypes(Request $request): array
    {
        $header = (string) $request->headers->get('Accept', '');
        $types = [];

        foreach (explode(',', $header) as $part) {
            $type = strtolower(trim(explode(';', $part)[0]));

            if ($type !== '' && $type !== '*/*') {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @return array<string, string>
     */
    private function stringQuery(Request $request): array
    {
        $query = [];

        foreach ($request->query->all() as $name => $value) {
            if (is_scalar($value)) {
                $query[(string) $name] = (string) $value;
            }
        }

        return $query;
    }
}
