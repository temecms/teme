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

    /** The keys the companion never sends, whatever is asked and in any case, on a site whose table prefix is wp_ (WordPressNames::NEVER_SENT). */
    private const array NEVER_SENT = ['user_pass', 'session_tokens', '_application_passwords', 'mailserver_pass', 'wp_user_roles', 'wp_capabilities'];

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

    /** @var array{options: array<string, string>, posts: list<array<string, mixed>>, users: array<int, array<string, list<string>>>, terms: list<array<string, mixed>>}|null */
    private ?array $wordpress = null;

    /** @var array{whole: array<string, mixed>, followers: list<array<string, mixed>>, reply_sources: list<array<string, mixed>>, permalinks: array<int, string>}|null */
    private ?array $fediverse = null;

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
     * What the site's own tables hold for the extension route
     * (docs/specs/companion-extension-route.md): options by name, posts,
     * people's meta by user ID and terms, each value as WordPress stores it,
     * serialized or plain; each meta key with its list of values.
     *
     * @param  array<string, string>  $options
     * @param  list<array<string, mixed>>  $posts
     * @param  array<int, array<string, list<string>>>  $users
     * @param  list<array<string, mixed>>  $terms
     */
    public function wordpress(array $options = [], array $posts = [], array $users = [], array $terms = []): self
    {
        $this->wordpress = ['options' => $options, 'posts' => $posts, 'users' => $users, 'terms' => $terms];

        return $this;
    }

    /**
     * What the WordPress ActivityPub plugin holds, for the fediverse route
     * (docs/specs/companion-fediverse-export.md): the route's whole answer,
     * its followers and reply sources as items of its pages, and older
     * posts' permalinks by ID.
     *
     * @param  array<string, mixed>  $whole
     * @param  list<array<string, mixed>>  $followers
     * @param  list<array<string, mixed>>  $replySources
     * @param  array<int, string>  $permalinks
     */
    public function fediverse(array $whole, array $followers = [], array $replySources = [], array $permalinks = []): self
    {
        $this->fediverse = ['whole' => $whole, 'followers' => $followers, 'reply_sources' => $replySources, 'permalinks' => $permalinks];

        return $this;
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

        if ($route === 'extension' && $this->wordpress !== null && (int) ($this->fixture('probe')['api'] ?? 1) >= 2) {
            return $this->extension($query);
        }

        if ($route === 'fediverse' && $this->fediverse !== null && (int) ($this->fixture('probe')['api'] ?? 1) >= 3) {
            return $this->fediverseAnswer($query);
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

    /**
     * The extension route's answer, as the companion's API 2 shapes it: a
     * never-sent key refused, serialized values decoded, a PHP object sent as
     * null and named under `objects`, and only what was asked for.
     *
     * @param  array<string, mixed>  $query
     */
    private function extension(array $query): PromiseInterface
    {
        $names = fn (string $key): array => array_values(array_filter(explode(',', (string) ($query[$key] ?? '')), fn (string $name): bool => $name !== ''));
        $kind = (string) ($query['kind'] ?? '');
        $asked = match ($kind) {
            'options' => $names('names'),
            'posts' => $names('meta'),
            'usermeta', 'termmeta' => $names('keys'),
            default => null,
        };

        if ($asked === null) {
            return Http::response(['code' => 'rest_invalid_param', 'message' => 'Invalid parameter(s): kind', 'data' => ['status' => 400]], 400);
        }

        if (array_intersect(array_map(strtolower(...), $asked), self::NEVER_SENT) !== []) {
            return Http::response(['code' => 'teme_never_sent', 'message' => 'Teme never asks for that.', 'data' => ['status' => 400]], 400);
        }

        /** @var array{options: array<string, string>, posts: list<array<string, mixed>>, users: array<int, array<string, list<string>>>, terms: list<array<string, mixed>>} $site */
        $site = $this->wordpress;

        if ($kind === 'options') {
            $options = [];
            $objects = [];

            foreach ($asked as $name) {
                [$options[$name], $object] = self::decoded($site['options'][$name] ?? null);

                if ($object) {
                    $objects[] = $name;
                }
            }

            return Http::response(['options' => $options, 'objects' => $objects]);
        }

        $records = match ($kind) {
            'posts' => array_map(fn (array $post): array => [
                'id' => $post['id'], 'type' => $post['type'], 'status' => $post['status'] ?? 'publish', 'guid' => $post['guid'] ?? self::SITE.'/?p='.$post['id'],
                'title' => $post['title'] ?? '', 'content' => $post['content'] ?? '', 'date_gmt' => $post['date_gmt'] ?? '2024-01-02 03:04:05',
                'author' => $post['author'] ?? 1, 'parent' => $post['parent'] ?? 0, 'meta' => $post['meta'] ?? [],
            ], array_values(array_filter($site['posts'], fn (array $post): bool => $post['type'] === ($query['type'] ?? null)))),
            'usermeta' => array_map(fn (int $id, array $meta): array => ['id' => $id, 'meta' => $meta], array_keys($site['users']), $site['users']),
            default => array_values(array_filter($site['terms'], fn (array $term): bool => ! isset($query['taxonomy']) || $term['taxonomy'] === $query['taxonomy'])),
        };

        $items = [];

        foreach ($records as $record) {
            $meta = [];
            $objects = [];

            foreach ($asked as $key) {
                if (! array_key_exists($key, $record['meta'])) {
                    continue;
                }

                foreach ($record['meta'][$key] as $value) {
                    [$meta[$key][], $object] = self::decoded($value);

                    if ($object && ! in_array($key, $objects, true)) {
                        $objects[] = $key;
                    }
                }
            }

            // A person or a term without any key asked for isn't listed; a post always is.
            if ($kind !== 'posts' && $meta === []) {
                continue;
            }

            $items[] = [...$record, 'meta' => $meta, ...($objects === [] ? [] : ['objects' => $objects])];
        }

        $after = max(0, (int) ($query['after_id'] ?? 0));
        $limit = min($kind === 'posts' ? 200 : 500, max(1, (int) ($query['limit'] ?? 200)));
        $items = array_values(array_filter($items, fn (array $item): bool => (int) $item['id'] > $after));
        usort($items, fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $items = array_slice($items, 0, $limit);

        return Http::response([
            'api' => 2,
            'items' => $items,
            'next' => count($items) >= $limit ? ['after_id' => (int) $items[count($items) - 1]['id']] : null,
            'limit' => $limit,
        ]);
    }

    /**
     * The fediverse route's answer, as the companion's API 3 shapes it: the
     * whole answer without a kind; a keyset page of followers or reply
     * sources; the permalinks of the posts named, at most 200.
     *
     * @param  array<string, mixed>  $query
     */
    private function fediverseAnswer(array $query): PromiseInterface
    {
        /** @var array{whole: array<string, mixed>, followers: list<array<string, mixed>>, reply_sources: list<array<string, mixed>>, permalinks: array<int, string>} $site */
        $site = $this->fediverse;
        $kind = (string) ($query['kind'] ?? '');

        if ($kind === '') {
            return Http::response($site['whole']);
        }

        if ($kind === 'permalinks') {
            $ids = array_slice(array_map(intval(...), array_filter(explode(',', (string) ($query['ids'] ?? '')), is_numeric(...))), 0, 200);

            return Http::response(['permalinks' => (object) array_intersect_key($site['permalinks'], array_flip($ids))]);
        }

        if (! in_array($kind, ['followers', 'reply_sources'], true)) {
            return Http::response(['code' => 'rest_invalid_param', 'message' => 'Invalid parameter(s): kind', 'data' => ['status' => 400]], 400);
        }

        $after = max(0, (int) ($query['after_id'] ?? 0));
        $limit = min($kind === 'followers' ? 200 : 500, max(1, (int) ($query['limit'] ?? 200)));
        $items = array_values(array_filter($site[$kind], fn (array $item): bool => (int) $item['id'] > $after));
        usort($items, fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $items = array_slice($items, 0, $limit);

        return Http::response([
            'api' => 3,
            'items' => $items,
            'next' => count($items) >= $limit ? ['after_id' => (int) $items[count($items) - 1]['id']] : null,
            'limit' => $limit,
        ]);
    }

    /**
     * A stored value as the companion sends it, as maybe_unserialize() reads
     * it, and whether it held a PHP object, which is sent as null.
     *
     * @return array{0: mixed, 1: bool}
     */
    private static function decoded(?string $stored): array
    {
        if ($stored === null) {
            return [null, false];
        }

        $value = @unserialize($stored, ['allowed_classes' => false]);

        if ($value === false && $stored !== 'b:0;') {
            return [$stored, false];
        }

        $object = is_object($value);

        if (is_array($value)) {
            array_walk_recursive($value, function (mixed $part) use (&$object): void {
                $object = $object || is_object($part);
            });
        }

        return $object ? [null, true] : [$value, false];
    }
}
