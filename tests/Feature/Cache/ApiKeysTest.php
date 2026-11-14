<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageEntry;
use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageStore;
use App\Domain\Cache\PageTags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
});

/**
 * The key PageKey gives this address, or a failure when it gives none.
 */
function apiKeyOf(string $uri): PageKey
{
    return PageKey::of(Request::create($uri)) ?? throw new LogicException("No key for {$uri}.");
}

it('keeps every parameter of an API address, sorted', function (): void {
    $given = apiKeyOf('/api/v1/items?type=movie&limit=2');
    $sorted = apiKeyOf('/api/v1/items?limit=2&type=movie');

    expect($given->url)->toBe('http://localhost/api/v1/items?limit=2&type=movie')
        ->and($sorted->url)->toBe($given->url)
        ->and(apiKeyOf('/api/v1/terms?taxonomy=genre&after=WyJuYW1lIl0&limit=5')->url)
        ->toBe('http://localhost/api/v1/terms?after=WyJuYW1lIl0&limit=5&taxonomy=genre');
});

it('encodes each kept API parameter by RFC 3986', function (): void {
    expect(apiKeyOf('/api/v1/items?published_since=2026-11-10T09:30:00%2B01:00&type=movie,series')->url)
        ->toBe('http://localhost/api/v1/items?published_since=2026-11-10T09%3A30%3A00%2B01%3A00&type=movie%2Cseries');
});

it('keeps only s for a page of the site, as before', function (): void {
    expect(apiKeyOf('/news/?utm_source=x&s=ferry')->url)->toBe('http://localhost/news/?s=ferry')
        ->and(apiKeyOf('/news/?utm_source=x')->url)->toBe('http://localhost/news/');
});

it('gives no key for an API parameter holding a list', function (): void {
    expect(PageKey::of(Request::create('/api/v1/items?type[]=movie')))->toBeNull()
        ->and(PageKey::of(Request::create('/api/v1/items?limit=2&type[0]=movie')))->toBeNull();
});

it('gives an API key with a query the search lifetime, and one without the full lifetime', function (): void {
    expect(apiKeyOf('/api/v1/items?limit=2')->search)->toBeTrue()
        ->and(apiKeyOf('/api/v1/items')->search)->toBeFalse()
        ->and(apiKeyOf('/api/v1/items/01jcb7x0q8m2v3k4n5p6r7s8t9')->search)->toBeFalse();
});

it('tags every API answer at an address with that address\'s tag, whatever its query', function (): void {
    $tag = PageTags::url('/api/v1/items');

    expect(apiKeyOf('/api/v1/items')->urlTag())->toBe($tag)
        ->and(apiKeyOf('/api/v1/items?limit=2')->urlTag())->toBe($tag)
        ->and(apiKeyOf('/api/v1/items?type=movie&after=WyJuYW1lIl0')->urlTag())->toBe($tag);
});

it('serves a stored answer to a repeated parameter as parse_str reads it, never a 400', function (): void {
    $folder = cacheOn();
    $body = '{"data":[],"meta":{"limit":3},"links":{"self":"http://localhost/api/v1/items?limit=3","next":null}}';
    $key = apiKeyOf('/api/v1/items?limit=3');
    $now = now()->getTimestamp();
    app(PageStore::class)->put(new PageEntry($key->url, 200, ['Content-Type' => ['application/json']], $body, $now, $now + PageCache::SEARCH_LIFETIME, [$key->urlTag()]));

    $served = cacheGet('/api/v1/items?limit=2&limit=3');

    expect(apiKeyOf('/api/v1/items?limit=2&limit=3')->url)->toBe('http://localhost/api/v1/items?limit=3')
        ->and($served->getStatusCode())->toBe(200)
        ->and($served->headers->get('X-Teme-Cache'))->toBe('HIT')
        ->and((string) $served->getContent())->toBe($body);

    cacheOff($folder);
});
