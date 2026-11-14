<?php

declare(strict_types=1);

use App\Domain\Api\ApiNotFound;
use App\Domain\Api\ApiPaths;
use App\Domain\Api\ApiQuery;
use App\Domain\Api\ApiResponse;
use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\PageWarming;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cache\SignedInMarker;
use App\Domain\Cache\StaticPages;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Http\Api\Middleware\EnsureApiOn;
use App\Http\Site\Middleware\MarkCacheable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Teme\Contracts\Cache\CacheKeysFor;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    Switches::apiOn();
    $this->folder = cacheOn();
    $this->id = strtolower((string) Str::ulid());
    $this->draws = 0;
    // The first public type, read from the registry, never named here.
    $this->type = collect(app(ContentTypeRegistry::class)->all())->first(fn (ContentType $type): bool => $type->public)?->key
        ?? throw new LogicException('No public type is registered.');

    // The probe in the api group, standing in for the real endpoints until the join.
    Route::middleware(['api', EnsureApiOn::class, 'throttle:api'])->prefix('api/v1')->group(function (): void {
        Route::get('_probe/{id}', function (string $id) {
            $this->draws++;
            app(RenderRecord::class)->tag(PageTags::content($id));

            return ApiResponse::one(['id' => $id], ['self' => url("/api/v1/_probe/{$id}")]);
        });

        Route::get('_probe-missing/{id}', function (string $id): never {
            $this->draws++;
            app(RenderRecord::class)->tag(PageTags::content($id));

            throw ApiNotFound::item($id);
        });

        Route::get('_probe-list', function (Request $request) {
            $limit = ApiQuery::limit(ApiQuery::from($request, ['limit'])->get('limit'));
            $this->draws++;
            app(RenderRecord::class)->tag(PageTags::listOf($this->type));

            return ApiResponse::list([['draw' => $this->draws]], $limit, url('/api/v1/_probe-list'), null);
        });
    });
});

afterEach(function (): void {
    cacheOff($this->folder);
    cacheOffValkey($this->prefix ?? null);
});

/**
 * Puts the page cache on this store for the test: the file store
 * beforeEach() set, or the Redis store against Valkey (cacheOnRedis(),
 * which skips the test when none answers).
 */
function apiCachingOn(string $store): void
{
    if ($store === 'redis') {
        test()->prefix = cacheOnRedis();
    }
}

dataset('api page stores', ['the file store' => 'file', 'the Redis store' => 'redis']);

it('stores an API answer and serves it, MISS then HIT, with the page cache\'s Cache-Control', function (string $store): void {
    apiCachingOn($store);
    $address = "/api/v1/_probe/{$this->id}";

    $drawn = cacheGet($address)->assertOk()
        ->assertHeader('X-Teme-Cache', 'MISS')
        ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=60');
    $served = cacheGet($address)->assertOk()
        ->assertHeader('X-Teme-Cache', 'HIT')
        ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=60')
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect((string) $served->getContent())->toBe((string) $drawn->getContent())
        ->and($served->json('data'))->toBe(['id' => $this->id])
        ->and($this->draws)->toBe(1)
        ->and(cacheEntry($address)?->tags)->toContain(PageTags::content($this->id), PageTags::url($address), 'seo');
})->with('api page stores');

it('stores a marked route\'s Markdown, for the .md pages', function (string $store): void {
    apiCachingOn($store);
    Route::middleware(['public', MarkCacheable::class])->get('_probe.md', fn () => response("# Probe\n", 200, ['Content-Type' => 'text/markdown; charset=UTF-8']));

    cacheGet('/_probe.md')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    cacheGet('/_probe.md')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');
})->with('api page stores');

it('stores a not_found problem for ten seconds', function (string $store): void {
    apiCachingOn($store);
    $address = "/api/v1/_probe-missing/{$this->id}";

    $drawn = cacheGet($address)->assertNotFound()
        ->assertHeader('X-Teme-Cache', 'MISS')
        ->assertHeader('Content-Type', 'application/problem+json');
    $entry = cacheEntry($address);
    $served = cacheGet($address)->assertNotFound()->assertHeader('X-Teme-Cache', 'HIT');

    // A hidden thing answers as one that never existed: the stored 404 is the drawn one.
    expect($entry?->expiresAt - $entry?->storedAt)->toBe(PageCache::NOT_FOUND_LIFETIME)
        ->and((string) $served->getContent())->toBe((string) $drawn->getContent())
        ->and($served->json('code'))->toBe('not_found')
        ->and($served->headers->get('ETag'))->toBeNull()
        ->and($this->draws)->toBe(1);

    $this->travel(PageCache::NOT_FOUND_LIFETIME + 1)->seconds();

    cacheGet($address)->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS');

    expect($this->draws)->toBe(2);
})->with('api page stores');

it('never stores a 400, 405 or 429', function (string $store): void {
    apiCachingOn($store);
    cacheGet('/api/v1/_probe-list?colour=red')->assertStatus(400)->assertHeader('X-Teme-Cache', 'BYPASS (status 400)');

    // A 405 is never a GET's: any other method is passed by before a key is made.
    $refused = cacheGet("/api/v1/_probe/{$this->id}", method: 'POST')->assertStatus(405);

    expect($refused->headers->get('X-Teme-Cache'))->not->toBe('MISS');

    $key = md5('api'.'api:127.0.0.1');

    for ($hit = 0; $hit < ApiPaths::PER_MINUTE; $hit++) {
        RateLimiter::hit($key, 60);
    }

    cacheGet("/api/v1/_probe/{$this->id}")->assertStatus(429)->assertHeader('X-Teme-Cache', 'BYPASS (status 429)');

    expect(cacheEntry('/api/v1/_probe-list?colour=red'))->toBeNull()
        ->and(cacheEntry("/api/v1/_probe/{$this->id}"))->toBeNull();
})->with('api page stores');

it('stores the api_off answer of an address with no query, and never one with a query, counting each', function (string $store): void {
    apiCachingOn($store);
    Switches::apiOff();
    $key = md5('api'.'api:127.0.0.1');
    RateLimiter::clear($key);

    // As D16 wants: stored at its path alone, tagged seo, so ticking the switch on purges it.
    cacheGet('/api/v1/items')->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS')->assertJsonPath('code', 'api_off');

    expect(cacheEntry('/api/v1/items')?->tags)->toContain('seo');

    for ($n = 1; $n <= 50; $n++) {
        cacheGet("/api/v1/items?x={$n}")->assertNotFound()->assertJsonPath('code', 'api_off')->assertHeader('X-Teme-Cache', 'BYPASS (not found with a query)');
        cacheGet("/api/v9/nothing?x={$n}")->assertNotFound()->assertJsonPath('code', 'not_found')->assertHeader('X-Teme-Cache', 'BYPASS (not found with a query)');
    }

    $stored = array_filter(range(1, 50), fn (int $n): bool => cacheEntry("/api/v1/items?x={$n}") !== null || cacheEntry("/api/v9/nothing?x={$n}") !== null);

    // Every one counted by the limit, the catch-all's too.
    expect($stored)->toBe([])
        ->and(RateLimiter::attempts($key))->toBe(101);

    Switches::apiOn();

    expect(cacheEntry('/api/v1/items'))->toBeNull();
})->with('api page stores');

it('purges a stored API answer when what it tagged changes', function (string $store): void {
    apiCachingOn($store);
    $answer = "/api/v1/_probe/{$this->id}";
    $missing = "/api/v1/_probe-missing/{$this->id}";
    $other = '/api/v1/_probe/'.strtolower((string) Str::ulid());

    foreach ([$answer, $missing, $other] as $address) {
        cacheGet($address)->assertHeader('X-Teme-Cache', 'MISS');
    }

    cacheProbe(new CacheKeysFor(CacheKeysFor::CONTENT, $this->id, [PageTags::content($this->id)]))->execute(schedProbeInput());

    expect(cacheKept($answer, $missing, $other))->toBe([$other]);

    cacheGet($answer)->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    cacheGet($missing)->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS');
    cacheGet($other)->assertHeader('X-Teme-Cache', 'HIT');
})->with('api page stores');

it('sets a stored list aside when its type\'s list changes, serving it STALE while one request draws it', function (string $store): void {
    apiCachingOn($store);
    cacheGet('/api/v1/_probe-list')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $this->travel(3)->seconds();

    cacheProbe(new CacheKeysFor(CacheKeysFor::CONTENT, $this->id, [PageTags::listOf($this->type)]))->execute(schedProbeInput());
    $lock = cacheDrawing('/api/v1/_probe-list');

    cacheGet('/api/v1/_probe-list')->assertOk()
        ->assertHeader('X-Teme-Cache', 'STALE')
        ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=0')
        ->assertHeader('Age', '3')
        ->assertJsonPath('data.0.draw', 1);

    $lock->release();

    cacheGet('/api/v1/_probe-list')->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->assertJsonPath('data.0.draw', 2);
    cacheGet('/api/v1/_probe-list')->assertOk()->assertHeader('X-Teme-Cache', 'HIT')->assertJsonPath('data.0.draw', 2);
})->with('api page stores');

it('keeps the ETag of a stored answer, so a matching If-None-Match gets 304 from the store', function (string $store): void {
    apiCachingOn($store);
    $address = "/api/v1/_probe/{$this->id}";
    $etag = (string) cacheGet($address)->assertHeader('X-Teme-Cache', 'MISS')->headers->get('ETag');

    $served = $this->get($address, ['If-None-Match' => $etag]);

    expect($etag)->toMatch('/^"[0-9a-f]{32}"$/')
        ->and(cacheEntry($address)?->headers['ETag'] ?? null)->toBe([$etag])
        ->and(cacheGet($address)->headers->get('ETag'))->toBe($etag)
        ->and($served->getStatusCode())->toBe(304)
        ->and($served->headers->get('X-Teme-Cache'))->toBe('HIT')
        ->and((string) $served->getContent())->toBe('')
        ->and($this->draws)->toBe(1);

    // A 404 carries no ETag, so a hidden thing is never given a 304.
    $this->get("/api/v1/_probe-missing/{$this->id}", ['If-None-Match' => $etag])->assertNotFound();
    $this->get("/api/v1/_probe-missing/{$this->id}", ['If-None-Match' => '*'])->assertNotFound()->assertHeader('X-Teme-Cache', 'HIT');
})->with('api page stores');

it('keeps no cookie and no rate-limit header in a stored answer', function (string $store): void {
    apiCachingOn($store);
    $address = "/api/v1/_probe/{$this->id}";

    cacheGet($address)->assertHeader('X-Teme-Cache', 'MISS');
    $served = cacheGet($address)->assertHeader('X-Teme-Cache', 'HIT');
    $stored = array_map(strtolower(...), array_keys(cacheEntry($address)?->headers ?? []));

    expect($stored)->toContain('etag')
        ->not->toContain('x-ratelimit-limit')
        ->not->toContain('x-ratelimit-remaining')
        ->not->toContain('retry-after')
        ->not->toContain('set-cookie')
        ->and($served->headers->has('X-RateLimit-Limit'))->toBeFalse()
        ->and($served->headers->has('X-RateLimit-Remaining'))->toBeFalse()
        ->and($served->headers->getCookies())->toBe([]);
})->with('api page stores');

it('tells a reader their own count only on an answer no shared cache may keep, and on the 429', function (string $store): void {
    apiCachingOn($store);
    $address = "/api/v1/_probe/{$this->id}";
    $limit = (string) ApiPaths::PER_MINUTE;

    // A shared cache in front may keep these for everyone, so they carry no one's count.
    $drawn = cacheGet($address)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=60');
    $stored = cacheGet($address)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    foreach ([$drawn, $stored] as $shared) {
        expect($shared->headers->has('X-RateLimit-Limit'))->toBeFalse()
            ->and($shared->headers->has('X-RateLimit-Remaining'))->toBeFalse()
            ->and($shared->headers->has('Retry-After'))->toBeFalse();
    }

    // Never stored, marked private: the reader's own.
    $refused = cacheGet('/api/v1/_probe-list?colour=red')->assertStatus(400)->assertHeader('X-RateLimit-Limit', $limit);
    config(['page_cache.enabled' => false]);
    $uncached = cacheGet($address)->assertOk()->assertHeader('X-RateLimit-Limit', $limit)->assertHeader('X-RateLimit-Remaining');

    expect($refused->headers->get('Cache-Control'))->toContain('private')
        ->and($uncached->headers->get('Cache-Control'))->toContain('private');

    $key = md5('api'.'api:127.0.0.1');

    for ($hit = 0; $hit < ApiPaths::PER_MINUTE; $hit++) {
        RateLimiter::hit($key, 60);
    }

    config(['page_cache.enabled' => true]);
    cacheGet('/api/v1/_probe/'.strtolower((string) Str::ulid()))->assertStatus(429)
        ->assertHeader('X-RateLimit-Limit', $limit)
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertHeader('Retry-After');
})->with('api page stores');

it('never warms an API answer, llms.txt or a .md page after a change', function (string $store): void {
    apiCachingOn($store);
    cacheShown([
        '/api/v1/_probe/x' => ['content:x'],
        '/llms.txt' => ['content:x'],
        '/about.md' => ['content:x'],
        '/about/' => ['content:x'],
    ]);
    cacheWarmOn();

    expect(app(PageWarming::class)->addresses(['content:x'], []))->toBe(['http://localhost/about/']);
})->with('api page stores');

it('passes a signed-in reader\'s API request by, and answers it the same', function (string $store): void {
    apiCachingOn($store);
    $address = "/api/v1/_probe/{$this->id}";
    $signedIn = [SignedInMarker::COOKIE => '1'];

    $theirs = cacheGet($address, $signedIn)->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (signed in)');

    expect(cacheEntry($address))->toBeNull();

    $anyones = cacheGet($address)->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    expect((string) $theirs->getContent())->toBe((string) $anyones->getContent());

    cacheGet($address, $signedIn)->assertHeader('X-Teme-Cache', 'BYPASS (signed in)');

    expect($this->draws)->toBe(3);
})->with('api page stores');

it('never makes an API answer a static file', function (): void {
    cacheOff($this->folder);
    $this->folder = cacheOnStatic();
    $address = "/api/v1/_probe/{$this->id}";

    cacheGet($address)->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $entry = cacheEntry($address);

    expect($entry)->not->toBeNull()
        ->and(StaticPages::wanted($entry))->toBeFalse()
        ->and(StaticPages::pathOf($entry->key))->toBe($address);
});
