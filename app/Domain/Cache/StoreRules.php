<?php

declare(strict_types=1);

namespace App\Domain\Cache;

use App\Domain\Api\ApiPaths;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the page cache never stores (docs/studies/page-cache.md §3.1,
 * I-262): anything but a GET's page or "not found", a response that sets a
 * cookie, is marked private, varies by something the key doesn't hold, is
 * encoded or streamed, or is neither HTML nor, from a route marked
 * cacheable, XML, plain text, JSON or Markdown; an API "not found" asked
 * with a query, which would be stored once for every value anyone sends;
 * and a degraded render. Each rule names itself, and the name is the
 * reason `X-Teme-Cache` gives.
 */
final class StoreRules
{
    /** What a stored page may vary by: the key holds the host, and the body is stored unencoded. */
    private const array VARY = ['accept-encoding', 'host'];

    /**
     * What Symfony sends when nothing set a Cache-Control
     * (ResponseHeaderBag::computeCacheControlValue()): not a choice to honour.
     */
    private const array DEFAULT_CACHE_CONTROL = ['no-cache, private', 'private, must-revalidate'];

    /**
     * What a route marked cacheable may answer, beside HTML, and still be
     * stored (I-464): a feed, a sitemap and its stylesheet, robots.txt and
     * llms.txt, the API's answers and its problems, and a .md page.
     */
    private const array MARKED_TYPES = ['application/rss+xml', 'application/xml', 'text/xml', 'application/xslt+xml', 'text/xsl', 'text/plain', 'application/json', 'application/activity+json', 'application/problem+json', 'text/markdown'];

    /**
     * Why this response may not be stored, or null when it may.
     */
    public static function refusal(Request $request, Response $response, ?string $renderRefusal): ?string
    {
        $status = $response->getStatusCode();
        $markedRedirect = PageCache::marked($request) && $status === 301;

        return match (true) {
            // Symfony empties a HEAD's body before any middleware sees it.
            ! $request->isMethod('GET') => 'head',
            // A marked route's permanent redirect (ads.txt to another host) is stored like its text.
            $response->isRedirection() && ! $markedRedirect => 'redirect',
            $response->isServerError() => 'server error',
            ! in_array($status, $markedRedirect ? [200, 301, 404] : [200, 404], true) => "status {$status}",
            // An API address's key keeps every parameter, so each value anyone sends would store a 404 (I-3324).
            $status === 404 && ApiPaths::is($request->getPathInfo()) && $request->getQueryString() !== null => 'not found with a query',
            $response->getContent() === false => 'streamed',
            $response->headers->getCookies() !== [] => 'sets a cookie',
            self::markedPrivate($response) => 'private',
            self::variesOtherwise($response) => 'vary',
            $response->headers->has('Content-Encoding') => 'encoded',
            ! self::storableType($request, $response) => PageCache::marked($request) ? 'not xml or text' : 'not html',
            $renderRefusal !== null => $renderRefusal,
            default => null,
        };
    }

    /**
     * Whether the answer is of a type the page cache stores: HTML from any
     * public route; one of MARKED_TYPES from a route marked cacheable.
     */
    private static function storableType(Request $request, Response $response): bool
    {
        $type = strtolower((string) $response->headers->get('Content-Type', ''));

        if (str_starts_with($type, 'text/html')) {
            return true;
        }

        return PageCache::marked($request) && in_array(trim(explode(';', $type)[0]), self::MARKED_TYPES, true);
    }

    private static function markedPrivate(Response $response): bool
    {
        $value = strtolower(trim((string) $response->headers->get('Cache-Control', '')));

        return ! in_array($value, self::DEFAULT_CACHE_CONTROL, true)
            && preg_match('/(?:^|[\s,])(?:private|no-store|no-cache)(?:$|[\s,=])/', $value) === 1;
    }

    private static function variesOtherwise(Response $response): bool
    {
        foreach ($response->headers->all('vary') as $line) {
            foreach (explode(',', (string) $line) as $token) {
                $token = strtolower(trim($token));

                if ($token !== '' && ! in_array($token, self::VARY, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
