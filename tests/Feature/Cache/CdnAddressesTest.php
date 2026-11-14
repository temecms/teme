<?php

declare(strict_types=1);

use App\Domain\Cache\CdnPurges;
use App\Domain\Cache\Jobs\PurgeCdn;
use App\Domain\Cache\PageCache;
use App\Domain\Cache\StaticPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cache\CacheKeysFor;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->folder = cacheOnStatic();
});

afterEach(fn () => cacheOff($this->folder));

it('purges at Cloudflare the address of each file a change removes, after its tags (D10)', function (): void {
    cacheCdnOn();
    cacheFiled(['/ferry/' => ['content:ferry'], '/news/' => ['list:post']]);

    cacheProbe(new CacheKeysFor(CacheKeysFor::CONTENT, 'ferry', ['content:ferry', 'list:post']))->execute(schedProbeInput());

    expect(cacheStaticFile('/ferry/'))->toBeNull()
        ->and(cacheCdnSent())->toBe([
            ['tags' => CdnPurges::keys(['content:ferry', 'list:post'])],
            ['files' => ['http://localhost/ferry/', 'http://localhost/news/']],
        ])
        ->and(DB::table('page_cache_cdn_addresses')->count())->toBe(0);
});

it('queues no address without a CDN', function (): void {
    cacheFiled(['/ferry/' => ['content:ferry']]);

    app(PageCache::class)->invalidate(['content:ferry']);

    expect(cacheStaticFile('/ferry/'))->toBeNull()
        ->and(DB::table('page_cache_cdn_addresses')->count())->toBe(0);
});

it('purges no address for a page that had no file', function (): void {
    cacheCdnOn();
    cacheFiled(['/ferry/' => ['content:ferry']]);
    // A minute's life, less than StaticPages::EARLY: stored, with no file.
    cachePage('/tides/', tags: ['content:tides']);

    app(PageCache::class)->invalidate(['content:ferry', 'content:tides']);

    expect(cacheCdnSent())->toBe([
        ['tags' => CdnPurges::keys(['content:ferry', 'content:tides'])],
        ['files' => ['http://localhost/ferry/']],
    ]);
});

it('sends addresses a hundred a request on a budget of their own, while the tags wait for theirs (D10)', function (): void {
    cacheCdnOn();
    $tags = array_map(fn (int $number): string => 'content:item-'.$number, range(1, 600));
    $paths = array_map(fn (int $number): string => '/item-'.$number.'/', range(1, 250));
    app(CdnPurges::class)->queue($tags);
    app(CdnPurges::class)->queueAddresses($paths);

    $drain = (new PurgeCdn)->withFakeQueueInteractions();
    $drain->handle(app(CdnPurges::class));

    $drain->assertReleased(60);
    expect(array_map(fn (array $body): string => (string) array_key_first($body), cacheCdnSent()))
        ->toBe(['tags', 'tags', 'tags', 'tags', 'tags', 'files', 'files', 'files'])
        ->and(array_merge(...array_column(cacheCdnSent(), 'files')))->toBe(array_map(fn (string $path): string => 'http://localhost'.$path, $paths))
        ->and(DB::table('page_cache_cdn_purges')->count())->toBe(100)
        ->and(DB::table('page_cache_cdn_addresses')->count())->toBe(0);
});

it('waits a second once the addresses\' budget for the second is spent', function (): void {
    cacheCdnOn();
    app(CdnPurges::class)->queueAddresses(array_map(fn (int $number): string => '/item-'.$number.'/', range(1, 1000)));

    $drain = (new PurgeCdn)->withFakeQueueInteractions();
    $drain->handle(app(CdnPurges::class));

    $drain->assertReleased(1);
    expect(cacheCdnSent())->toHaveCount(8)
        ->and(DB::table('page_cache_cdn_addresses')->count())->toBe(200);
});

it('forgets the addresses queued before the whole site is purged, which covers them (I-520)', function (): void {
    cacheCdnOn();
    app(CdnPurges::class)->queueAddresses(['/ferry/']);

    $this->artisan('teme:cache:clear')->assertSuccessful();

    expect(cacheCdnSent())->toBe([['hosts' => ['localhost']]])
        ->and(DB::table('page_cache_cdn_addresses')->count())->toBe(0);
});

it('purges the address of each file the prune removes (D7)', function (): void {
    cacheCdnOn();
    cacheFiled(['/ferry/' => ['content:ferry']]);

    $this->travel(3600 - StaticPages::EARLY)->seconds();
    $this->artisan('teme:cache:prune')->assertSuccessful();

    expect(cacheCdnSent())->toBe([['files' => ['http://localhost/ferry/']]]);
});
