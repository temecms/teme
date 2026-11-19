<?php

/**
 * The address a stored page answers (docs/studies/page-cache.md §3.2): the
 * scheme and host, the path exactly as asked, and of the query the names a
 * public page or an enabled declared route depends on (plus `s`), so tracking
 * parameters share a page; when a path varies by Accept, the key carries that
 * type too. Cookies are never part of it. The path keeps its case, because
 * 1.6a answers an address in capitals with a 301 to its one true form.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cache;

use App\Domain\Api\ApiPaths;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use Illuminate\Http\Request;

final readonly class PageKey
{
    /** The query parameters a public page's answer depends on without plugins: PathResolver reads `s` (1.6h). */
    public const array QUERY = ['s'];

    /**
     * @param  bool  $search  whether the key keeps a query the reader wrote: a search's page, or an API answer with parameters
     */
    private function __construct(
        public string $url,
        public string $path,
        public bool $search,
    ) {}

    /**
     * The key for this request, or null when a parameter it keeps isn't
     * plain text, such as `s[]=` or an API address's `type[]=`, for which
     * nothing is stored; or when an uncacheable conditional declared route
     * on the path reads one of the request's query names or Accept types
     * (docs/plans/plugin-public-routes.md).
     */
    public static function of(Request $request): ?self
    {
        // The query string PathResolver parses (PublicController hands it
        // over), so the two read one `s`.
        parse_str((string) $request->getQueryString(), $parameters);
        $path = $request->getPathInfo();
        $stringQuery = [];

        foreach ($parameters as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $stringQuery[$name] = $value;
            }
        }

        $registry = app(PublicRouteRegistry::class);

        if ($registry->uncacheableConditionTouches($path, $stringQuery, PublicRouteRegistry::acceptTypes($request))) {
            return null;
        }

        // An API answer depends on every parameter its route takes, and the
        // API refuses any other, so a stored answer is never one a typo made.
        $names = ApiPaths::is($path) ? array_map(strval(...), array_keys($parameters)) : $registry->cacheQueryNames();
        $kept = [];

        foreach ($names as $name) {
            if (! array_key_exists($name, $parameters)) {
                continue;
            }

            if (! is_string($parameters[$name])) {
                return null;
            }

            $kept[$name] = $parameters[$name];
        }

        ksort($kept, SORT_STRING);
        $query = $kept === [] ? '' : '?'.http_build_query($kept, '', '&', PHP_QUERY_RFC3986);
        $accept = self::acceptVariant($request, $registry->acceptTypesFor($path));
        $suffix = $accept === null ? '' : '|accept:'.$accept;

        return new self(strtolower($request->getSchemeAndHttpHost()).$path.$query.$suffix, $path, $kept !== []);
    }

    /**
     * The tag every page stored at this address carries, whatever its case
     * or query: `url:` and a hash of the address in the form addresses are
     * stored in, so a purge of the address finds them all.
     */
    public function urlTag(): string
    {
        return PageTags::url($this->path);
    }

    /**
     * The name of the lock one request holds while it draws the page at
     * this key, so the others wait for it or get its stale copy rather than
     * draw it too (docs/studies/page-cache.md §3.9).
     */
    public function lockName(): string
    {
        return 'page-cache:'.hash('sha256', $this->url);
    }

    /**
     * @param  list<string>  $declared
     */
    private static function acceptVariant(Request $request, array $declared): ?string
    {
        if ($declared === []) {
            return null;
        }

        $header = (string) $request->headers->get('Accept', '');

        foreach (explode(',', $header) as $part) {
            $type = strtolower(trim(explode(';', $part)[0]));

            if ($type !== '' && in_array($type, $declared, true)) {
                return $type;
            }
        }

        return null;
    }
}
