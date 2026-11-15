<?php

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Cache\PageCache;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\PublicRoutes\RouteDispatcher;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves an unconditional declared public route looked up from the registry
 * by method and path.
 */
final class DeclaredRouteController extends Controller
{
    public function __invoke(Request $request, PublicRouteRegistry $registry, RouteDispatcher $dispatcher): Response
    {
        $accept = $this->acceptTypes($request);
        $query = $this->stringQuery($request);
        $route = $registry->match($request->getMethod(), $request->getPathInfo(), $query, $accept);

        if ($route === null || $route->isConditional()) {
            throw new NotFoundHttpException;
        }

        if ($route->isCacheable()) {
            $request->attributes->set(PageCache::CACHEABLE, true);
        }

        return $dispatcher->dispatch($route, $request);
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
