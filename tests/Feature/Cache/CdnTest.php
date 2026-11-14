<?php

declare(strict_types=1);

use App\Domain\Cache\CdnBusy;
use App\Domain\Cache\CdnPurges;
use App\Domain\Cache\CloudflareCdn;
use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageTags;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ApiRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Mid-year, so no new year falls inside the hour a test gives a page.
    $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC'));
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('tells Cloudflare a stored page\'s tags, hashed, and the whole life it has left, and every other shared cache a minute (D1, D2)', function (): void {
    config(['page_cache.lifetime' => 3600]);
    $story = pageArticle($this->author, 'Big story');

    // No CDN configured: nothing for one.
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS')
        ->assertHeaderMissing('Cache-Tag')
        ->assertHeaderMissing('Cloudflare-CDN-Cache-Control');

    cacheCdnOn();
    $this->travel(600)->seconds();

    $hit = addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'HIT')
        ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=60')
        ->assertHeader('Cloudflare-CDN-Cache-Control', 'max-age=3000');
    $tags = cacheEntry('/big-story/')?->tags ?? [];

    expect($tags)->toContain('content:'.$story->id)
        ->and((string) $hit->headers->get('Cache-Tag'))->toBe(implode(',', CdnPurges::keys($tags)))
        ->toMatch('/^[0-9a-f]{16}(,[0-9a-f]{16})*$/')
        ->not->toContain($story->id);
});

it('reads nothing from the database to answer from the cache with a CDN in front', function (): void {
    cacheCdnOn();
    pageArticle($this->author, 'Big story');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');

    DB::enableQueryLog();
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'HIT')->assertHeader('Cloudflare-CDN-Cache-Control');

    expect(DB::getQueryLog())->toBe([]);
});

it('tells a CDN nothing about a stale copy, which it then keeps no time (D8)', function (): void {
    cacheCdnOn();
    cachePage('/', '<p>The front page as it was</p>', tags: [PageTags::url('/'), 'list:post']);
    app(PageCache::class)->invalidate(['list:post']);
    cacheDrawing('/');

    addrGet('/')->assertHeader('X-Teme-Cache', 'STALE')
        ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=0')
        ->assertHeaderMissing('Cache-Tag')
        ->assertHeaderMissing('Cloudflare-CDN-Cache-Control');
});

it('gives a page whose keys don\'t fit Cloudflare\'s 16 KB no CDN headers, so no shared cache keeps it longer than a minute (D3)', function (): void {
    cacheCdnOn();
    // A thousand tags: 17 bytes each with its comma, past 16 KB.
    cachePage('/crowded/', tags: array_map(fn (int $number): string => 'content:crowd-'.$number, range(1, 1000)));

    addrGet('/crowded/')->assertHeader('X-Teme-Cache', 'HIT')
        ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=60')
        ->assertHeaderMissing('Cache-Tag')
        ->assertHeaderMissing('Cloudflare-CDN-Cache-Control');
});

it('purges Cloudflare by tag, and the whole site by its host, never the whole zone, with its token (D6)', function (): void {
    cacheCdnOn();
    $cloudflare = app(CdnPurges::class)->driver();

    expect($cloudflare)->toBeInstanceOf(CloudflareCdn::class);

    $cloudflare?->purgeKeys(['0123456789abcdef', 'fedcba9876543210']);
    $cloudflare?->purgeSite();

    Http::assertSent(fn (ApiRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-test/purge_cache'
        && $request->hasHeader('Authorization', 'Bearer token-test'));
    expect(cacheCdnSent())->toBe([['tags' => ['0123456789abcdef', 'fedcba9876543210']], ['hosts' => ['localhost']]]);
});

it('says how long Cloudflare asks it to wait, and why Cloudflare refused a purge', function (): void {
    cacheCdnOn(Http::sequence()
        ->push(['success' => false, 'errors' => [['code' => 971, 'message' => 'Please wait and consider throttling your request speed']]], 429, ['Retry-After' => '30'])
        ->push(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403));
    $cloudflare = app(CdnPurges::class)->driver();

    expect(fn () => $cloudflare?->purgeKeys(['0123456789abcdef']))->toThrow(CdnBusy::class, 'wait 30 seconds')
        ->and(fn () => $cloudflare?->purgeKeys(['0123456789abcdef']))->toThrow(RuntimeException::class, 'Authentication error');
});

it('refuses a CDN it has no driver for, and Cloudflare without its zone or token (D15)', function (): void {
    config(['page_cache.cdn.driver' => 'fastly']);

    expect(fn () => app(CdnPurges::class)->driver())->toThrow(InvalidArgumentException::class, 'no CDN driver called [fastly]');

    config(['page_cache.cdn.driver' => 'cloudflare', 'page_cache.cdn.cloudflare.zone' => 'zone-test', 'page_cache.cdn.cloudflare.token' => null]);

    expect(fn () => app(CdnPurges::class)->driver())->toThrow(InvalidArgumentException::class, 'CLOUDFLARE_API_TOKEN');
});
