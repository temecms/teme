<?php

declare(strict_types=1);

namespace Tests\Fixtures\Fediverse;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

/**
 * Requests to the public site as another server sends them: through the
 * whole kernel, page cache included, with the Accept a fediverse server
 * gives, or a browser's.
 */
final class Requests
{
    public const string ACTIVITY = 'application/activity+json';

    public const string LD = 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

    /**
     * An address on the site, keeping the slash before a query that url() drops.
     */
    public static function url(string $path): string
    {
        return rtrim(url('/'), '/').$path;
    }

    /**
     * @param  array<string, string>  $headers  extra headers, by their names
     */
    public static function get(string $uri, string $accept = self::ACTIVITY, array $headers = []): TestResponse
    {
        return self::send(Request::create($uri, 'GET', server: self::server(['Accept' => $accept, ...$headers])));
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function post(string $uri, string $body, array $headers = [], string $address = '203.0.113.9'): TestResponse
    {
        $server = [...self::server(['Content-Type' => 'application/activity+json', ...$headers]), 'REMOTE_ADDR' => $address];

        return self::send(Request::create($uri, 'POST', server: $server, content: $body));
    }

    private static function send(Request $request): TestResponse
    {
        return TestResponse::fromBaseResponse(app(Kernel::class)->handle($request), $request);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @return array<string, string>
     */
    private static function server(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }
}
