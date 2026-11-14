<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageEntry;
use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageStore;
use App\Domain\Cache\PurgeTimes;
use App\Domain\Cache\RenderRecord;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->folder = cacheOn();

    // Pages during whose drawing something else happens, a second later.
    Route::middleware('public')->prefix('cache-race')->group(function (): void {
        Route::get('ferry', function () {
            app(RenderRecord::class)->tag('content:ferry');

            // Another request publishes the ferry timetable while this page is drawn, once.
            if (! app()->bound('cache-race.published')) {
                app()->instance('cache-race.published', true);
                test()->travel(1)->seconds();
                app(PageCache::class)->purge(['content:ferry']);
            }

            return response('<p>The timetable as it was</p>');
        });
        Route::get('tides', function () {
            app(RenderRecord::class)->tag('content:tides');

            return response('<p>Tides</p>');
        });
        Route::get('slow', function () {
            test()->travel(PageCache::RENDER_WINDOW + 1)->seconds();

            return response('<p>Slow</p>');
        });
    });
});

afterEach(fn () => cacheOff($this->folder));

it('never stores a page one of whose tags was purged while it was drawn (I-264)', function (): void {
    addrGet('/cache-race/ferry')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (purged while drawn)');

    expect(cacheEntry('/cache-race/ferry'))->toBeNull();
});

it('stores the page drawn after the purge', function (): void {
    addrGet('/cache-race/ferry')->assertHeader('X-Teme-Cache', 'BYPASS (purged while drawn)');

    addrGet('/cache-race/ferry')->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/cache-race/ferry')->assertHeader('X-Teme-Cache', 'HIT');
});

it('forgets a page it wrote as a purge of one of its tags landed', function (): void {
    $files = app(PageStore::class);

    // The purge lands between the page cache's first look and its write.
    $this->app->bind(PageStore::class, fn (): PageStore => new class($files) implements PageStore
    {
        public function __construct(private readonly PageStore $files) {}

        public function get(PageKey $key): ?PageEntry
        {
            return $this->files->get($key);
        }

        public function put(PageEntry $entry): void
        {
            test()->travel(1)->seconds();
            app(PurgeTimes::class)->record(['content:tides'], now());
            $this->files->put($entry);
        }

        public function stale(PageKey $key): ?PageEntry
        {
            return $this->files->stale($key);
        }

        public function markStale(array $tags, int $until): int
        {
            return $this->files->markStale($tags, $until);
        }

        public function purge(array $tags): int
        {
            return $this->files->purge($tags);
        }

        public function forget(string $key): void
        {
            $this->files->forget($key);
        }

        public function flush(): int
        {
            return $this->files->flush();
        }

        public function prune(int $now): int
        {
            return $this->files->prune($now);
        }

        public function lock(PageKey $key, int $seconds): Lock
        {
            return $this->files->lock($key, $seconds);
        }

        public function keys(array $tags, int $limit): array
        {
            return [];
        }
    });

    addrGet('/cache-race/tides')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (purged while drawn)');

    expect(cacheEntry('/cache-race/tides'))->toBeNull();
});

it('never stores a page drawn for longer than purges are remembered', function (): void {
    addrGet('/cache-race/slow')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (drawn too slowly)');

    expect(cacheEntry('/cache-race/slow'))->toBeNull();
});

it('never stores a page drawn before teme:cache:clear, after it', function (): void {
    Route::middleware('public')->get('cache-race/cleared', function () {
        app(RenderRecord::class)->tag('content:harbour');
        test()->travel(1)->seconds();
        Artisan::call('teme:cache:clear');

        return response('<p>The harbour as it was</p>');
    });

    addrGet('/cache-race/cleared')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (purged while drawn)');
});

it('numbers purges in the order they are noted, whatever their stamps say', function (): void {
    $purges = app(PurgeTimes::class);
    $before = $purges->latest();

    // A worker whose clock runs a minute ahead, then one a minute behind.
    $purges->record(['content:ferry'], now()->addMinute());
    $purges->record(['content:tides'], now()->subMinute());

    expect($purges->latest())->toBeGreaterThan($before)
        ->and($purges->since(['content:tides'], $before))->toBeTrue()
        ->and($purges->since(['content:harbour'], $before))->toBeFalse()
        ->and($purges->since(['content:ferry', 'content:tides'], $purges->latest()))->toBeFalse();
});

it('never stores a page when a purge lands while it is drawn, even one stamped by a clock that runs behind', function (): void {
    Route::middleware('public')->get('cache-race/lagging', function () {
        app(RenderRecord::class)->tag('content:harbour');

        // The queue worker publishes the harbour page meanwhile, its clock a minute behind this server's.
        app(PurgeTimes::class)->record(['content:harbour'], now()->subMinute());

        return response('<p>The harbour as it was</p>');
    });

    addrGet('/cache-race/lagging')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (purged while drawn)');

    expect(cacheEntry('/cache-race/lagging'))->toBeNull();
});

it('stores a page drawn after a purge stamped by a clock that runs ahead', function (): void {
    // The queue worker, its clock a minute ahead of this server's, purged the tides page just before.
    app(PurgeTimes::class)->record(['content:tides'], now()->addMinute());

    addrGet('/cache-race/tides')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
});

it('indexes the purges by tag and number, for the guard, and by time, for the prune', function (): void {
    expect(DB::table('pg_indexes')->where('tablename', 'page_cache_purges')->pluck('indexname')->all())
        ->toEqualCanonicalizing(['page_cache_purges_pkey', 'page_cache_purges_tag_id_index', 'page_cache_purges_purged_at_index']);
});
