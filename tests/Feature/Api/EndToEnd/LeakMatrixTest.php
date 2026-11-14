<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Api\ApiPaths;
use App\Domain\Contents\Models\Content;
use App\Domain\Media\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Fixtures\Api\CatalogueProbe;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\EndToEnd;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

// The join (I-3324): every route of the API, llms.txt and every .md address
// of the fixture site, with the page cache on and off, and nothing a reader
// can't see in any answer, stored or drawn.

beforeEach(function (): void {
    $this->freezeSecond();
    $this->site = DeliverySite::build();
    Switches::apiOn();
    Switches::llmsOn();
    $this->folder = cacheOn();
});

afterEach(function (): void {
    cacheOff($this->folder);
    cacheOffValkey($this->prefix ?? null);
});

/**
 * Puts the page cache on this store: the file store beforeEach() set, or
 * the Redis store, on the Valkey test service.
 */
function leakStoreOn(string $store): void
{
    if ($store === 'redis') {
        test()->prefix = cacheOnRedis();
    }
}

dataset('leak passes', [
    'the page cache off' => [false, 'file'],
    'the page cache on, the file store' => [true, 'file'],
    'the page cache on, the Redis store' => [true, 'redis'],
]);

dataset('leak stores', ['the file store' => 'file', 'the Redis store' => 'redis']);

/**
 * Every address the walk asks for: each route of the API with each kind of
 * parameter, each item by ID and by every address it ever had, each term
 * and file by ID, llms.txt, and the .md address of every address an item
 * ever had.
 *
 * @return list<string>
 */
function leakAddresses(DeliverySite $site): array
{
    $items = [
        $site->public, $site->sequel, $site->protected, $site->private, $site->draft, $site->newerDraft, $site->scheduled,
        $site->trashed, $site->region, $site->about, $site->ahead, $site->pointer, $site->retired,
    ];
    $files = [$site->shell, $site->poster, $site->draftOnly, $site->privateOnly, $site->protectedOnly, $site->hiddenOnly, $site->regionOnly, $site->socialOnly];
    $paths = DB::table('paths')->where('target_type', 'content')->orderBy('path')->pluck('path')->map(fn (mixed $path): string => (string) $path)->all();
    $authors = DB::table('users')->whereIn('id', [$site->author->id, $site->author2->id, $site->admin->id])->pluck('slug')->all();

    $addresses = [
        '/api/v1', '/api/v1/types', '/api/v1/taxonomies',
        '/api/v1/terms', '/api/v1/terms?taxonomy=genre', '/api/v1/terms?taxonomy=shelf', '/api/v1/terms?parent=none', '/api/v1/terms?slug=top-shelf',
        '/api/v1/terms/'.$site->genre->id, '/api/v1/terms/'.$site->shelf->id,
        '/api/v1/items', '/api/v1/items?limit=100', '/api/v1/items?sort=published_at&limit=100', '/api/v1/items?type=movie', '/api/v1/items?type=documentary',
        '/api/v1/items?term='.$site->genre->id, '/api/v1/items?term='.$site->shelf->id,
        '/api/v1/items/by-path?path=%2F', '/api/v1/nothing', '/api', '/llms.txt', '/index.md',
    ];

    foreach ($authors as $slug) {
        $addresses[] = '/api/v1/items?author='.$slug;
    }

    foreach ($items as $item) {
        $addresses[] = '/api/v1/items/'.$item->id;
    }

    foreach ($files as $file) {
        $addresses[] = '/api/v1/media/'.$file->id;
    }

    foreach ($paths as $path) {
        $addresses[] = EndToEnd::byPath('/'.$path.'/');
        $addresses[] = '/'.$path.'.md';
    }

    return $addresses;
}

/**
 * What no answer may hold: the strings and IDs of everything a reader
 * can't see.
 *
 * @return array<string, string>
 */
function leakSecrets(DeliverySite $site): array
{
    return [
        'the newer draft' => 'SECRET',
        'the password' => DeliverySite::PASSWORD,
        'the author\'s email' => 'ada@example.com',
        'the administration email' => 'owner-only@secret.example',
        'the hidden notes' => DeliverySite::NOTES_VALUE,
        'the hidden backstage file' => $site->hiddenOnly->id,
        'the protected words' => DeliverySite::PROTECTED_WORDS,
        'the protected tagline' => DeliverySite::PROTECTED_TAGLINE,
        'the private item\'s title' => 'Hidden',
        'the private item\'s ID' => $site->private->id,
        'the draft\'s title' => 'Unfinished',
        'the draft\'s ID' => $site->draft->id,
        'the shelf term' => 'Top shelf',
        'the shelf term\'s ID' => $site->shelf->id,
        'a link by ID' => 'teme:',
        'a button card\'s article' => 'contentId',
    ];
}

/**
 * The answer's body with what the address itself asked for taken out: an
 * ID or address a problem names back tells nothing the asker didn't send.
 */
function leakBody(string $address, string $body): string
{
    $parts = parse_url($address);
    parse_str($parts['query'] ?? '', $query);
    $asked = array_filter([...explode('/', (string) ($parts['path'] ?? '')), ...array_values(array_filter($query, 'is_string'))], fn (string $part): bool => strlen($part) > 3);
    usort($asked, fn (string $one, string $other): int => strlen($other) <=> strlen($one));

    return str_replace($asked, '', $body);
}

it('gives nothing a reader can\'t see at any address, stored or drawn', function (bool $cached, string $store): void {
    $site = $this->site;
    leakStoreOn($store);
    config(['page_cache.enabled' => $cached]);
    CatalogueProbe::general($site->admin, ['admin_email' => 'owner-only@secret.example']);
    $found = [];
    $walked = '';

    foreach (leakAddresses($site) as $address) {
        // The walk asks for more than a minute's count from one reader, at one frozen second.
        RateLimiter::clear(md5('api'.'api:127.0.0.1'));

        // Twice: with the page cache on, the second is the stored copy.
        foreach ([EndToEnd::get($address), EndToEnd::get($address)] as $response) {
            $body = leakBody($address, (string) $response->getContent());
            $walked .= $body;

            if ($response->getStatusCode() === 429 || $response->getStatusCode() >= 500) {
                $found[] = "{$address}: status {$response->getStatusCode()}";
            }

            foreach (leakSecrets($site) as $what => $secret) {
                if (str_contains($body, $secret)) {
                    $found[] = "{$address}: {$what}";
                }
            }
        }
    }

    // The walk reached what a reader can see: Arrival's words, its sequel's address, a stored copy.
    expect($found)->toBe([])
        ->and($walked)->toContain('Louise Banks teaches languages.')
        ->toContain(Addresses::urlFor($site->sequel))
        ->toContain('# Arrival')
        ->and(cacheEntry('/api/v1/items/'.$site->public->id) !== null)->toBe($cached);
})->with('leak passes');

/**
 * A problem's answer as an array, with what the request itself asked for
 * put back as `{asked}`, so a hidden thing's answer and a made-up one's can
 * be compared whole.
 *
 * @return array<array-key, mixed>
 */
function leakProblem(string $body, string $asked): array
{
    $problem = (array) json_decode($body, true);
    array_walk_recursive($problem, function (mixed &$value) use ($asked): void {
        $value = is_string($value) ? str_replace([$asked, rawurlencode($asked)], '{asked}', $value) : $value;
    });

    return $problem;
}

it('answers each hidden item and file asked for directly as one that never existed, and lists none of them', function (bool $cached, string $store): void {
    $site = $this->site;
    leakStoreOn($store);
    config(['page_cache.enabled' => $cached]);
    $items = [$site->private, $site->draft, $site->scheduled, $site->trashed, $site->region, $site->ahead, $site->retired];
    $files = [$site->draftOnly, $site->privateOnly, $site->protectedOnly, $site->hiddenOnly, $site->regionOnly, $site->socialOnly];
    $never = strtolower((string) Str::ulid());
    $nowhere = '/movies/never-was-here/';
    $asked = [];

    foreach ($items as $item) {
        $asked['/api/v1/items/'.$item->id] = [$item->id, '/api/v1/items/'.$never, $never];

        foreach (DB::table('paths')->where('target_type', 'content')->where('target_id', $item->id)->pluck('path') as $path) {
            $asked[EndToEnd::byPath('/'.$path.'/')] = ['/'.$path.'/', EndToEnd::byPath($nowhere), $nowhere];
        }
    }

    foreach ($files as $file) {
        // A made-up ID written as the file's own is, upper case.
        $asked['/api/v1/media/'.$file->id] = [$file->id, '/api/v1/media/'.strtoupper($never), strtoupper($never)];
    }

    $differ = [];

    foreach ($asked as $address => [$own, $madeUp, $madeUpOwn]) {
        RateLimiter::clear(md5('api'.'api:127.0.0.1'));

        // Twice each: with the page cache on, the second answer is the stored copy.
        foreach ([[EndToEnd::get($address), EndToEnd::get($madeUp)], [EndToEnd::get($address), EndToEnd::get($madeUp)]] as [$hidden, $missing]) {
            if ($hidden->getStatusCode() !== 404 || leakProblem((string) $hidden->getContent(), $own) !== leakProblem((string) $missing->getContent(), $madeUpOwn)) {
                $differ[] = "{$address}: {$hidden->getStatusCode()} ".$hidden->getContent();
            }
        }
    }

    $lists = array_values(array_filter(leakAddresses($site), fn (string $address): bool => $address === '/llms.txt' || preg_match('#^/api/v1/(items|terms)(\?|$)#', $address) === 1));
    $names = [
        ...array_map(fn (Content $item): string => (string) $item->title, $items),
        ...array_merge(...array_map(fn (Media $file): array => [(string) $file->title, (string) $file->original_filename, (string) $file->name], $files)),
    ];
    $listed = [];

    foreach ($lists as $list) {
        RateLimiter::clear(md5('api'.'api:127.0.0.1'));

        foreach ([EndToEnd::get($list), EndToEnd::get($list)] as $response) {
            $body = (string) $response->getContent();

            foreach ($names as $name) {
                if (str_contains($body, $name)) {
                    $listed[] = "{$list}: {$name}";
                }
            }
        }
    }

    // The lists reached what a reader can see, and the comparison had something to compare.
    expect($differ)->toBe([])
        ->and($listed)->toBe([])
        ->and(count($asked))->toBeGreaterThan(count($items) + count($files))
        ->and($lists)->toContain('/api/v1/items?limit=100', '/llms.txt')
        ->and((string) EndToEnd::get('/api/v1/items?limit=100')->getContent())->toContain('Arrival II');
})->with('leak passes');

it('walks every page of the list without a hidden item, stored or drawn', function (bool $cached, string $store): void {
    $site = $this->site;
    leakStoreOn($store);
    config(['page_cache.enabled' => $cached]);
    $next = '/api/v1/items?limit=1';
    $seen = [];

    while ($next !== null) {
        $page = EndToEnd::get($next)->assertOk();
        array_push($seen, ...array_column($page->json('data'), 'id'));
        $link = $page->json('links.next');
        $next = is_string($link) ? (string) preg_replace('#^https?://[^/]+#', '', $link) : null;
    }

    expect($seen)->toContain($site->public->id, $site->sequel->id, $site->protected->id, $site->about->id, $site->pointer->id)
        ->not->toContain($site->private->id)
        ->not->toContain($site->draft->id)
        ->not->toContain($site->scheduled->id)
        ->not->toContain($site->trashed->id)
        ->not->toContain($site->region->id)
        ->not->toContain($site->ahead->id)
        ->not->toContain($site->retired->id)
        ->and(count($seen))->toBe(count(array_unique($seen)));
})->with('leak passes');

it('serves a stored answer to a request over the limit without counting it', function (string $store): void {
    leakStoreOn($store);
    $stored = '/api/v1/items/'.$this->site->public->id;
    $drawn = '/api/v1/items/'.$this->site->sequel->id;
    EndToEnd::get($stored)->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $key = md5('api'.'api:127.0.0.1');
    RateLimiter::clear($key);

    for ($hit = 0; $hit < ApiPaths::PER_MINUTE; $hit++) {
        RateLimiter::hit($key, 60);
    }

    EndToEnd::get($stored)->assertOk()->assertHeader('X-Teme-Cache', 'HIT')->assertJsonPath('data.title', 'Arrival');

    expect(RateLimiter::attempts($key))->toBe(ApiPaths::PER_MINUTE);

    EndToEnd::get($drawn)->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
})->with('leak stores');

it('answers 304 from a stored answer and from a drawn one', function (string $store): void {
    leakStoreOn($store);
    $address = '/api/v1/items/'.$this->site->public->id;
    $etag = (string) EndToEnd::get($address)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->headers->get('ETag');

    $stored = EndToEnd::get($address, ['If-None-Match' => $etag]);

    config(['page_cache.enabled' => false]);
    $fresh = EndToEnd::get($address)->assertOk();
    $drawn = EndToEnd::get($address, ['If-None-Match' => $etag]);

    expect($etag)->toMatch('/^"[0-9a-f]{32}"$/')
        ->and($stored->getStatusCode())->toBe(304)
        ->and($stored->headers->get('X-Teme-Cache'))->toBe('HIT')
        ->and((string) $stored->getContent())->toBe('')
        ->and($fresh->headers->get('ETag'))->toBe($etag)
        ->and($fresh->headers->has('X-Teme-Cache'))->toBeFalse()
        ->and($drawn->getStatusCode())->toBe(304)
        ->and($drawn->headers->has('X-Teme-Cache'))->toBeFalse()
        ->and((string) $drawn->getContent())->toBe('');
})->with('leak stores');

it('gives one stored answer whatever language or cookie the reader sends', function (string $store): void {
    leakStoreOn($store);
    $list = '/api/v1/items';
    $item = '/api/v1/items/'.$this->site->public->id;
    $first = EndToEnd::get($list)->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $one = EndToEnd::get($item)->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    $french = EndToEnd::get($list, ['Accept-Language' => 'fr-FR,fr;q=0.9'], ['locale' => 'fr', 'language' => 'pt-BR'])->assertOk()->assertHeader('X-Teme-Cache', 'HIT');
    $other = EndToEnd::get($item, ['Accept-Language' => 'pt-BR'], ['locale' => 'pt-BR'])->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    expect((string) $french->getContent())->toBe((string) $first->getContent())
        ->and((string) $other->getContent())->toBe((string) $one->getContent())
        ->and($other->json('data.language'))->toBe('en-GB');
})->with('leak stores');
