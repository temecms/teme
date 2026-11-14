<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Teme Companion plugin's export API, answered from the JSON fixtures
 * in Companion/, as the plugin's own code shapes it: keyset pages and their
 * envelope, Basic authentication checked as WordPress checks an
 * application password, both API roots, and the refusals a test scripts.
 * No test reaches a network.
 */
final class FakeCompanion
{
    public const string SITE = 'https://old.example';

    public const string HOST = 'old.example';

    public const string IP = '93.184.216.34';

    public const string USER = 'ada';

    public const string PASSWORD = 'abcd EFGH 1234 ijkl MNOP 5678';

    /** Each list route's page size and its largest, as the plugin's own Paging::read() calls give them. */
    private const array LIMITS = [
        'users' => [200, 500], 'terms' => [500, 1000], 'media' => [200, 500], 'content' => [50, 200],
        'comments' => [500, 1000], 'redirects' => [500, 1000], 'urls' => [1000, 2000],
    ];

    /** @var list<array{route: string, query: array<string, string>, signedIn: bool}> */
    public array $requests = [];

    /** @var array<string, array<int|string, mixed>> */
    private array $items = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>, headers: array<string, string>}>> */
    private array $scripted = [];

    private bool $prettyPermalinks = true;

    /**
     * Replaces a list route's items, or a whole route's answer, for this test.
     *
     * @param  array<int|string, mixed>  $items
     */
    public function items(string $route, array $items): self
    {
        $this->items[$route] = $items;

        return $this;
    }

    /**
     * The next request to this route gets this answer instead.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function answer(string $route, int $status, array $body = [], array $headers = []): self
    {
        $this->scripted[$route][] = ['status' => $status, 'body' => $body, 'headers' => $headers];

        return $this;
    }

    /**
     * The next request to this route never connects.
     */
    public function unreachable(string $route): self
    {
        return $this->answer($route, 0);
    }

    /**
     * The next request to this route breaks in Teme, with an error that is
     * neither a refusal nor a lost connection.
     */
    public function breaks(string $route): self
    {
        return $this->answer($route, -1);
    }

    /**
     * The site uses plain permalinks: /wp-json/ isn't there, ?rest_route=/ is.
     */
    public function plainPermalinks(): self
    {
        $this->prettyPermalinks = false;

        return $this;
    }

    /**
     * The requests made to this route, in order.
     *
     * @return list<array{route: string, query: array<string, string>, signedIn: bool}>
     */
    public function requestsTo(string $route): array
    {
        return array_values(array_filter($this->requests, fn (array $request): bool => $request['route'] === $route));
    }

    /**
     * The answer to one request, for Http::fake().
     */
    public function respond(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        parse_str($url['query'] ?? '', $query);
        $path = $url['path'] ?? '/';

        if (isset($query['rest_route'])) {
            $route = (string) preg_replace('#^/teme/v1/#', '', (string) $query['rest_route']);
            unset($query['rest_route']);
        } elseif ($this->prettyPermalinks && str_starts_with($path, '/wp-json/teme/v1/')) {
            $route = substr($path, strlen('/wp-json/teme/v1/'));
        } else {
            return Http::response('<html><title>Page not found</title></html>', 404, ['Content-Type' => 'text/html']);
        }

        $signedIn = $this->signedIn($request);
        $this->requests[] = ['route' => $route, 'query' => array_map(strval(...), $query), 'signedIn' => $signedIn];

        if (($this->scripted[$route] ?? []) !== []) {
            $next = array_shift($this->scripted[$route]);

            if ($next['status'] === -1) {
                throw new RuntimeException('The fixture broke.');
            }

            return $next['status'] === 0 ? Http::failedConnection()($request) : Http::response($next['body'], $next['status'], $next['headers']);
        }

        if ($route !== 'probe' && ! $signedIn) {
            return Http::response(['code' => 'teme_forbidden', 'message' => 'An administrator account is required.', 'data' => ['status' => 401]], 401);
        }

        $fixture = $this->fixture($route);

        if ($fixture === null) {
            return Http::response(['code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.', 'data' => ['status' => 404]], 404);
        }

        return Http::response(isset(self::LIMITS[$route]) ? $this->page($route, $fixture, $query) : $fixture);
    }

    /**
     * Whether the request signs in as the fixture's administrator: WordPress
     * strips all but letters and digits from an application password before
     * it checks it.
     */
    private function signedIn(Request $request): bool
    {
        $header = $request->header('Authorization')[0] ?? '';

        if (! str_starts_with($header, 'Basic ')) {
            return false;
        }

        [$user, $password] = array_pad(explode(':', (string) base64_decode(substr($header, 6)), 2), 2, '');

        return $user === self::USER
            && preg_replace('/[^a-z\d]/i', '', $password) === preg_replace('/[^a-z\d]/i', '', self::PASSWORD);
    }

    /**
     * The route's answer: a test's own items, else its fixture file, else null.
     *
     * @return array<int|string, mixed>|null
     */
    private function fixture(string $route): ?array
    {
        if (isset($this->items[$route])) {
            return isset(self::LIMITS[$route]) ? ['items' => $this->items[$route]] : $this->items[$route];
        }

        $path = __DIR__.'/Companion/'.$route.'.json';

        return is_file($path) ? (array) json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : null;
    }

    /**
     * One keyset page, as the plugin's Envelope::make() builds it: a full
     * page names the next, so the last full page is followed by an empty one.
     *
     * @param  array<int|string, mixed>  $fixture
     * @param  array<string, mixed>  $query
     *
     * @return array<string, mixed>
     */
    private function page(string $route, array $fixture, array $query): array
    {
        [$default, $max] = self::LIMITS[$route];
        $after = max(0, (int) ($query['after_id'] ?? 0));
        $limit = min($max, max(1, (int) ($query['limit'] ?? $default)));
        // Only content and media read modified_after, as in the plugin.
        $modifiedAfter = isset($query['modified_after']) && in_array($route, ['content', 'media'], true) ? (string) $query['modified_after'] : null;

        $items = array_values(array_filter(
            (array) ($fixture['items'] ?? []),
            fn (array $item): bool => (int) $item['id'] > $after && ($modifiedAfter === null || (string) ($item['modified_gmt'] ?? '') > $modifiedAfter),
        ));
        usort($items, fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $items = array_slice($items, 0, $limit);

        return [
            'api' => 1,
            'items' => $items,
            'next' => count($items) >= $limit ? ['after_id' => (int) $items[count($items) - 1]['id']] : null,
            'limit' => $limit,
        ];
    }
}
