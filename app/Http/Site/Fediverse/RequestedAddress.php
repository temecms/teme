<?php

declare(strict_types=1);

namespace App\Http\Site\Fediverse;

use Teme\Contracts\Routes\PublicRequest;

/**
 * The full address a fediverse request asked for, on the site's own base,
 * as fediverse_actors and fediverse_objects store theirs.
 */
final class RequestedAddress
{
    public static function of(PublicRequest $request): string
    {
        $query = http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);

        return rtrim(url('/'), '/').$request->path.($query === '' ? '' : '?'.$query);
    }
}
