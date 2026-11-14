<?php

declare(strict_types=1);

namespace App\Domain\Api;

/**
 * Where the API lives and what it allows a reader: every address under /api
 * is the API's (I-091), and each address may ask for so many drawn answers
 * a minute (docs/superpowers/plans/2026-11-11-phase3-slice31-delivery-api-first-release.md).
 */
final class ApiPaths
{
    /** Drawn answers a minute per address; the page cache's stored ones aren't counted. */
    public const int PER_MINUTE = 120;

    /**
     * Whether a request's path is the API's: /api itself or anything under
     * it, read as the router and the public site read it, decoded and in
     * any case, so /%61pi/x and /API/x are the API's too.
     */
    public static function is(string $path): bool
    {
        $path = strtolower(rawurldecode($path));

        return $path === '/api' || str_starts_with($path, '/api/');
    }
}
