<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Cache\PurgeTimes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('removes every stored page with teme:cache:clear', function (): void {
    cachePage('/harbour/');
    cachePage('/ferries/');

    $this->artisan('teme:cache:clear')->expectsOutputToContain('Removed 2 pages from the page cache.')->assertSuccessful();

    expect(cacheEntry('/harbour/'))->toBeNull()
        ->and(cacheEntry('/ferries/'))->toBeNull()
        ->and(DB::table('page_cache_tags')->count())->toBe(0);
});

it('removes only expired pages with teme:cache:prune', function (): void {
    cachePage('/old/', lifetime: 60);
    cachePage('/fresh/', lifetime: 600);
    $this->travel(61)->seconds();

    $this->artisan('teme:cache:prune')->expectsOutputToContain('Removed 1 expired page from the page cache.')->assertSuccessful();

    expect(cacheEntry('/old/'))->toBeNull()
        ->and(cacheEntry('/fresh/'))->not->toBeNull();
});

it('prunes the page cache on the schedule', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('teme:cache:prune')->assertSuccessful();
});

it('purges the pages that carry any tag given, with teme:cache:purge', function (): void {
    cachePage('/harbour/', tags: ['content:harbour']);
    cachePage('/ferries/', tags: ['list:ferries']);
    cachePage('/tides/', tags: ['content:tides']);

    $this->artisan('teme:cache:purge', ['tags' => ['content:harbour', 'list:ferries']])
        ->expectsOutputToContain('Removed 2 pages from the page cache.')
        ->assertSuccessful();

    expect(cacheKept('/harbour/', '/ferries/', '/tides/'))->toBe(['/tides/']);
});

it('purges every page at an address, whatever its case or query, with --url', function (): void {
    cachePage('/about/');
    cachePage('/About/');
    cachePage('/about/?s=heron');
    cachePage('/contact/');

    $this->artisan('teme:cache:purge', ['--url' => ['https://example.com/about/']])
        ->expectsOutputToContain('Removed 3 pages from the page cache.')
        ->assertSuccessful();

    expect(cacheKept('/about/', '/About/', '/about/?s=heron', '/contact/'))->toBe(['/contact/']);
});

it('purges nothing, and says what it needs, when given neither a tag nor an address', function (): void {
    cachePage('/about/');

    $this->artisan('teme:cache:purge')->expectsOutputToContain('Name a tag, or an address with --url.')->assertFailed();

    expect(cacheKept('/about/'))->toBe(['/about/']);
});

it('forgets purges older than any page still being drawn, with teme:cache:prune', function (): void {
    app(PurgeTimes::class)->record(['content:harbour'], now());
    $this->travel(PageCache::RENDER_WINDOW + 1)->seconds();
    app(PurgeTimes::class)->record(['content:ferries'], now());

    $this->artisan('teme:cache:prune')->assertSuccessful();

    expect(DB::table('page_cache_purges')->pluck('tag')->all())->toBe(['content:ferries']);
});

it('empties the store while the cache is off, so turning it on again starts afresh (I-475)', function (): void {
    cachePage('/harbour/', lifetime: 600);
    cachePage('/ferries/', lifetime: 600);
    config(['page_cache.enabled' => false]);

    $this->artisan('teme:cache:prune')->expectsOutputToContain('The page cache is off: removed 2 pages it held.')->assertSuccessful();

    expect(cacheEntry('/harbour/'))->toBeNull()
        ->and(cacheEntry('/ferries/'))->toBeNull()
        ->and(DB::table('page_cache_tags')->count())->toBe(0);
});
