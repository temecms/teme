<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageEntry;
use App\Domain\Cache\PageStore;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\StaticPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->folder = cacheOnStatic();
});

afterEach(fn () => cacheOff($this->folder));

it('writes a stored page as a plain file named by its address, keeping its case and its trailing slash (D3)', function (): void {
    cachePage('/Harbour/', '<p>The harbour</p>', 3600);
    cachePage('/tides', '<p>The tides</p>', 3600);
    cachePage('/', '<p>Home</p>', 3600);

    expect(cacheStaticFile('/Harbour/'))->toBe('<p>The harbour</p>')
        ->and(cacheStaticFile('/harbour/'))->toBeNull()
        ->and(cacheStaticFile('/tides'))->toBe('<p>The tides</p>')
        ->and(cacheStaticFile('/tides/'))->toBeNull()
        ->and(cacheStaticFile('/'))->toBe('<p>Home</p>')
        ->and(StaticPages::nameFor('/Harbour/'))->toBe('Harbour/index.html')
        ->and(StaticPages::nameFor('/tides'))->toBe('tidesindex.html')
        ->and(StaticPages::nameFor('/'))->toBe('index.html')
        ->and(StaticPages::nameFor('/caf%C3%A9/'))->toBe('café/index.html');
});

it('notes each file in page_cache_files, its row expiring two minutes before its page (D4, D7)', function (): void {
    $entry = cachePage('/harbour/', '<p>The harbour</p>', 3600);

    expect(DB::table('page_cache_files')->get(['entry', 'path'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe([['entry' => hash('sha256', 'http://localhost/harbour/'), 'path' => '/harbour/']])
        ->and(strtotime((string) DB::table('page_cache_files')->value('expires_at')))->toBe($entry->expiresAt - StaticPages::EARLY);
});

it('reports a file it can\'t write, keeps the page for PHP, and leaves the row with no file, never a file with no row (D4, D15)', function (): void {
    Exceptions::fake();
    // A folder where the file should go: the rename over it fails.
    mkdir(config('page_cache.stores.static.path').'/harbour/index.html', 0755, true);

    cachePage('/harbour/', '<p>The harbour</p>', 3600);

    Exceptions::assertReported(ErrorException::class);
    expect(cacheEntry('/harbour/'))->not->toBeNull()
        ->and(DB::table('page_cache_files')->pluck('path')->all())->toBe(['/harbour/'])
        ->and(cacheStaticFile('/harbour/'))->toBeNull();
});

it('writes no file for a page the web server could not serve as PHP does (D2)', function (string $key, int $status, array $headers, int $life): void {
    $now = now()->getTimestamp();
    $path = (string) parse_url($key, PHP_URL_PATH);

    app(PageStore::class)->put(new PageEntry($key, $status, $headers, '<p>Not for a file</p>', $now, $now + $life, [PageTags::url($path)]));

    expect(DB::table('page_cache_tags')->count())->toBe(1)
        ->and(DB::table('page_cache_files')->count())->toBe(0)
        ->and(cacheStaticFile($path))->toBeNull();
})->with([
    'a "not found" page' => ['http://localhost/harbour/', 404, ['Content-Type' => ['text/html; charset=UTF-8']], 3600],
    'a search' => ['http://localhost/?s=crew', 200, ['Content-Type' => ['text/html; charset=UTF-8']], 600],
    'another host' => ['http://elsewhere.test/harbour/', 200, ['Content-Type' => ['text/html; charset=UTF-8']], 3600],
    'another scheme' => ['https://localhost/harbour/', 200, ['Content-Type' => ['text/html; charset=UTF-8']], 3600],
    'a header the web server would drop' => ['http://localhost/harbour/', 200, ['Content-Type' => ['text/html; charset=UTF-8'], 'X-Robots-Tag' => ['noindex, follow']], 3600],
    'two minutes or less to live' => ['http://localhost/harbour/', 200, ['Content-Type' => ['text/html; charset=UTF-8']], StaticPages::EARLY],
    'an address the router keeps' => ['http://localhost/_teme/harbour/', 200, ['Content-Type' => ['text/html; charset=UTF-8']], 3600],
    'a hidden segment' => ['http://localhost/.harbour/', 200, ['Content-Type' => ['text/html; charset=UTF-8']], 3600],
    'an empty segment' => ['http://localhost//harbour/', 200, ['Content-Type' => ['text/html; charset=UTF-8']], 3600],
]);

it('removes a page\'s file when a change removes the page or sets it aside, so a copy set aside is never a file (D6)', function (): void {
    cacheFiled(['/ferry/' => ['content:ferry'], '/news/' => ['list:post'], '/tides/' => ['content:tides']]);

    app(PageCache::class)->invalidate(['content:ferry', 'list:post']);

    expect(cacheStaticFile('/ferry/'))->toBeNull()
        ->and(cacheStaticFile('/news/'))->toBeNull()
        ->and(cacheSetAside('/news/'))->not->toBeNull()
        ->and(cacheStaticFile('/tides/'))->toBe('<p>/tides/</p>');
});

it('removes the file of a page the race guard forgets, and of a page stored again with none', function (): void {
    cacheFiled(['/ferry/' => ['content:ferry'], '/tides/' => ['content:tides']]);
    $now = now()->getTimestamp();

    app(PageStore::class)->forget('http://localhost/ferry/');
    app(PageStore::class)->put(new PageEntry('http://localhost/tides/', 404, ['Content-Type' => ['text/html; charset=UTF-8']], '<p>Gone</p>', $now, $now + 10, [PageTags::url('/tides/')]));

    expect(cacheStaticFile('/ferry/'))->toBeNull()
        ->and(cacheStaticFile('/tides/'))->toBeNull();
});

it('removes every file the table knows when the store is flushed, even one whose page\'s rows are gone (D4)', function (): void {
    cacheFiled(['/ferry/' => ['content:ferry'], '/tides/' => ['content:tides']]);
    // A process that died between deleting a page's rows and its file.
    DB::table('page_cache_tags')->where('entry', hash('sha256', 'http://localhost/tides/'))->delete();

    $this->artisan('teme:cache:clear')->assertSuccessful();

    expect(cacheStaticFile('/ferry/'))->toBeNull()
        ->and(cacheStaticFile('/tides/'))->toBeNull();
});

it('removes a file two minutes before its page\'s life ends, and its row only once the file is gone (D7)', function (): void {
    cachePage('/ferry/', '<p>Ferry</p>', 3600, ['content:ferry']);

    $this->travel(3600 - StaticPages::EARLY - 1)->seconds();
    $this->artisan('teme:cache:prune')->assertSuccessful();

    expect(cacheStaticFile('/ferry/'))->toBe('<p>Ferry</p>');

    $this->travel(1)->seconds();
    $this->artisan('teme:cache:prune')->assertSuccessful();

    // PHP serves the page's last two minutes from the store.
    expect(cacheStaticFile('/ferry/'))->toBeNull()
        ->and(DB::table('page_cache_files')->count())->toBe(0)
        ->and(cacheEntry('/ferry/')?->expired(now()->getTimestamp()))->toBeFalse();
});

it('keeps the file of a page stored again when its old row\'s time is up', function (): void {
    cachePage('/ferry/', '<p>Old</p>', 3600, ['content:ferry']);
    $this->travel(600)->seconds();
    cachePage('/ferry/', '<p>New</p>', 3600, ['content:ferry']);

    $this->travel(3600 - 600 - StaticPages::EARLY)->seconds();
    $this->artisan('teme:cache:prune')->assertSuccessful();

    expect(cacheStaticFile('/ferry/'))->toBe('<p>New</p>')
        ->and(DB::table('page_cache_files')->count())->toBe(1);
});

it('removes every file within a minute of the static-file driver being switched off, and purges the site at the CDN once (D12)', function (): void {
    cacheCdnOn();
    cacheFiled(['/ferry/' => ['content:ferry']]);
    config(['page_cache.driver' => 'file']);

    $this->artisan('teme:cache:prune')->assertSuccessful();
    $this->artisan('teme:cache:prune')->assertSuccessful();

    expect(cacheStaticFile('/ferry/'))->toBeNull()
        ->and(DB::table('page_cache_files')->count())->toBe(0)
        ->and(cacheCdnSent())->toBe([['hosts' => ['localhost']]]);
});

it('removes every file within a minute of the cache being switched off (I-493)', function (): void {
    cacheFiled(['/ferry/' => ['content:ferry']]);
    config(['page_cache.enabled' => false]);

    $this->artisan('teme:cache:prune')->assertSuccessful();

    expect(cacheStaticFile('/ferry/'))->toBeNull();
});
