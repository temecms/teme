<?php

/**
 * Marks a public route whose answer is XML or plain text, a feed, a sitemap
 * or robots.txt, as one the page cache may store (I-464). HTML needs no
 * mark; anything else is never stored, marked or not.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site\Middleware;

use App\Domain\Cache\PageCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MarkCacheable
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(PageCache::CACHEABLE, true);

        return $next($request);
    }
}
