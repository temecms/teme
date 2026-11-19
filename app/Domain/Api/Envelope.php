<?php

/**
 * The API's own addresses as it writes them in `links`: a route's absolute
 * address made now (rule 9) with its query sorted by name and encoded by
 * RFC 3986, so the same call always has the same address.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api;

final class Envelope
{
    /**
     * The route's canonical absolute address with this query.
     *
     * @param  array<string, mixed>  $parameters  the route's own parameters
     * @param  array<string, string>  $query  query parameters; empty ones are kept, none is added
     */
    public static function link(string $routeName, array $parameters, array $query = []): string
    {
        $address = route($routeName, $parameters);

        if ($query === []) {
            return $address;
        }

        ksort($query, SORT_STRING);

        return $address.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
