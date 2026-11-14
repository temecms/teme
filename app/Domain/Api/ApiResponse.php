<?php

declare(strict_types=1);

namespace App\Domain\Api;

use Illuminate\Http\JsonResponse;
use stdClass;

/**
 * The API's two envelopes: one thing as `data` with its `links`, and a list
 * as `data` with `meta.limit` and `links.self` and `links.next`; `data`,
 * `meta` and `links` are the only top-level members of a success.
 */
final class ApiResponse
{
    /**
     * One thing: `{"data": {…}, "meta": {…}?, "links": {…}}`.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $links
     * @param  array<string, mixed>|null  $meta  left out when null
     */
    public static function one(array $data, array $links, ?array $meta = null): JsonResponse
    {
        $body = ['data' => $data === [] ? new stdClass : $data];

        if ($meta !== null) {
            $body['meta'] = $meta === [] ? new stdClass : $meta;
        }

        $body['links'] = $links === [] ? new stdClass : $links;

        return self::json($body);
    }

    /**
     * A list: `{"data": [ … ], "meta": {"limit": n}, "links": {"self": …, "next": … | null}}`.
     *
     * @param  list<array<string, mixed>>  $data
     */
    public static function list(array $data, int $limit, string $self, ?string $next): JsonResponse
    {
        return self::json(['data' => $data, 'meta' => ['limit' => $limit], 'links' => ['self' => $self, 'next' => $next]]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function json(array $body): JsonResponse
    {
        return new JsonResponse($body, 200, ['Content-Type' => 'application/json'], Problems::JSON_FLAGS);
    }
}
