<?php

declare(strict_types=1);

use App\Domain\Cache\PageEntry;
use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageStore;
use App\Domain\Cache\PageTags;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('keeps a page as one line of JSON and then its HTML, never serialized (rule 4)', function (): void {
    $entry = cachePage('/harbour/', '<p>The lifeboat crew</p>');
    [$line, $body] = explode("\n", (string) file_get_contents(app(PageStore::class)->pathOf('http://localhost/harbour/')), 2);

    expect(json_decode($line, true))->toBe([
        'key' => 'http://localhost/harbour/',
        'status' => 200,
        'headers' => ['Content-Type' => ['text/html; charset=UTF-8']],
        'storedAt' => $entry->storedAt,
        'expiresAt' => $entry->storedAt + 60,
        'tags' => $entry->tags,
    ])
        ->and($body)->toBe('<p>The lifeboat crew</p>')
        ->and(cacheEntry('/harbour/'))->toEqual($entry);
});

it('finds a page only under its own address', function (): void {
    cachePage('/harbour/');

    expect(cacheEntry('/harbour/'))->not->toBeNull()
        ->and(cacheEntry('/Harbour/'))->toBeNull()
        ->and(cacheEntry('/harbour/?s=crew'))->toBeNull();
});

it('records a page\'s tags with its expiry, and drops a tag it no longer has when stored again', function (): void {
    $tag = (string) PageKey::of(Request::create('/harbour/'))?->urlTag();
    cachePage('/harbour/', tags: [$tag, 'list:post']);
    cachePage('/harbour/', tags: [$tag]);

    expect(DB::table('page_cache_tags')->pluck('tag')->all())->toBe([$tag])
        ->and(strtotime((string) DB::table('page_cache_tags')->value('expires_at')))->toBe(now()->getTimestamp() + 60);
});

it('refuses a page with no tag, which nothing could find again', function (): void {
    expect(fn () => cachePage('/harbour/', tags: []))->toThrow(InvalidArgumentException::class)
        ->and(file_exists(app(PageStore::class)->pathOf('http://localhost/harbour/')))->toBeFalse();
});

it('deletes a page\'s file when its rows can\'t be written, so the table knows every file', function (): void {
    Schema::drop('page_cache_tags');

    expect(fn () => cachePage('/harbour/'))->toThrow(QueryException::class)
        ->and(file_exists(app(PageStore::class)->pathOf('http://localhost/harbour/')))->toBeFalse();
});

it('takes a file it can\'t read for no page', function (): void {
    cachePage('/harbour/');
    file_put_contents(app(PageStore::class)->pathOf('http://localhost/harbour/'), "{not json\n<p>Harbour</p>");

    expect(cacheEntry('/harbour/'))->toBeNull();
});

it('removes every page through the rows that record it, and says how many', function (): void {
    cachePage('/harbour/');
    cachePage('/ferries/');

    expect(app(PageStore::class)->flush())->toBe(2)
        ->and(cacheEntry('/harbour/'))->toBeNull()
        ->and(cacheEntry('/ferries/'))->toBeNull()
        ->and(DB::table('page_cache_tags')->count())->toBe(0);
});

it('prunes only expired pages, and keeps a file stored again after its rows expired', function (): void {
    cachePage('/old/', lifetime: 60);
    cachePage('/fresh/', lifetime: 600);
    cachePage('/again/', lifetime: 60);
    $this->travel(61)->seconds();

    // Stored again, with a new life, while its rows still say the old one:
    // the race prune must survive without deleting the new file.
    cachePage('/again/', lifetime: 60);
    DB::table('page_cache_tags')->where('entry', hash('sha256', 'http://localhost/again/'))->update(['expires_at' => '2000-01-01T00:00:00Z']);

    expect(app(PageStore::class)->prune(now()->getTimestamp()))->toBe(1)
        ->and(cacheEntry('/old/'))->toBeNull()
        ->and(cacheEntry('/fresh/'))->not->toBeNull()
        ->and(cacheEntry('/again/'))->not->toBeNull();
});

it('indexes the table by tag and by expiry, for purges and pruning', function (): void {
    expect(DB::table('pg_indexes')->where('tablename', 'page_cache_tags')->pluck('indexname')->all())
        ->toEqualCanonicalizing(['page_cache_tags_pkey', 'page_cache_tags_tag_index', 'page_cache_tags_expires_at_index']);
});

it('is on everywhere but a developer\'s machine unless PAGE_CACHE_ENABLED says otherwise, and off in the suite (I-460)', function (): void {
    expect((string) file_get_contents(config_path('page_cache.php')))->toContain("'enabled' => (bool) env('PAGE_CACHE_ENABLED', env('APP_ENV', 'production') !== 'local')")
        // Named, never set: a production .env copied from it can't turn the cache off.
        ->and((string) file_get_contents(base_path('.env.example')))->not->toMatch('/^PAGE_CACHE_ENABLED=/m')->toContain('# PAGE_CACHE_ENABLED=')
        ->and((string) file_get_contents(base_path('phpunit.xml')))->toContain('<env name="PAGE_CACHE_ENABLED" value="false"/>');
});

it('purges every page that carries any of the tags, with all its rows, and says how many', function (): void {
    cachePage('/harbour/', tags: ['content:harbour', 'list:news']);
    cachePage('/ferries/', tags: ['content:ferries', 'list:news']);
    cachePage('/tides/', tags: ['content:tides']);

    expect(app(PageStore::class)->purge(['list:news', 'content:nowhere']))->toBe(2)
        ->and(cacheEntry('/harbour/'))->toBeNull()
        ->and(cacheEntry('/ferries/'))->toBeNull()
        ->and(cacheEntry('/tides/'))->not->toBeNull()
        ->and(DB::table('page_cache_tags')->pluck('tag')->all())->toBe(['content:tides']);
});

it('purges every page for *', function (): void {
    cachePage('/harbour/', tags: ['content:harbour']);
    cachePage('/ferries/', tags: ['content:ferries']);

    expect(app(PageStore::class)->purge(['*']))->toBe(2)
        ->and(DB::table('page_cache_tags')->count())->toBe(0);
});

it('forgets one page and its rows, and leaves the rest', function (): void {
    cachePage('/harbour/');
    cachePage('/ferries/');

    app(PageStore::class)->forget('http://localhost/harbour/');

    expect(cacheEntry('/harbour/'))->toBeNull()
        ->and(cacheEntry('/ferries/'))->not->toBeNull()
        ->and(DB::table('page_cache_tags')->count())->toBe(1);
});

it('sets a page aside under a list\'s tag: stale() finds it, get() no longer does, and the page stored again takes its place', function (): void {
    cachePage('/front/', '<p>The front page as it was</p>', tags: [PageTags::url('/front/'), 'list:post']);

    expect(app(PageStore::class)->markStale(['list:post'], now()->getTimestamp() + 300))->toBe(1)
        ->and(cacheEntry('/front/'))->toBeNull()
        ->and(cacheSetAside('/front/')?->body)->toBe('<p>The front page as it was</p>');

    cachePage('/front/', '<p>The front page now</p>', tags: [PageTags::url('/front/'), 'list:post']);

    expect(cacheEntry('/front/')?->body)->toBe('<p>The front page now</p>')
        ->and(cacheSetAside('/front/'))->toBeNull();
});

it('sets aside each page carrying any of the tags, once', function (): void {
    cachePage('/one/', tags: [PageTags::url('/one/'), 'list:post', 'author:7']);
    cachePage('/two/', tags: [PageTags::url('/two/'), 'author:7']);
    cachePage('/three/', tags: [PageTags::url('/three/'), 'list:page']);

    expect(app(PageStore::class)->markStale(['list:post', 'author:7'], now()->getTimestamp() + 300))->toBe(2)
        ->and(cacheKept('/one/', '/two/', '/three/'))->toBe(['/three/']);
});

it('removes a set-aside copy with its page: by a purge of any of its tags, forget() and flush()', function (): void {
    foreach (['/one/', '/two/', '/three/'] as $uri) {
        cachePage($uri, tags: [PageTags::url($uri), 'list:post', 'content:'.trim($uri, '/')]);
    }
    app(PageStore::class)->markStale(['list:post'], now()->getTimestamp() + 300);

    app(PageStore::class)->purge(['content:one']);
    app(PageStore::class)->forget('http://localhost/two/');

    expect(cacheSetAside('/one/'))->toBeNull()
        ->and(cacheSetAside('/two/'))->toBeNull()
        ->and(cacheSetAside('/three/'))->not->toBeNull();

    app(PageStore::class)->flush();

    expect(cacheSetAside('/three/'))->toBeNull();
});

it('keeps a set-aside copy until the moment it was given, and prunes it then', function (): void {
    cachePage('/front/', lifetime: 3600, tags: [PageTags::url('/front/'), 'list:post']);
    app(PageStore::class)->markStale(['list:post'], now()->getTimestamp() + 300);

    app(PageStore::class)->prune(now()->getTimestamp() + 299);

    expect(cacheSetAside('/front/'))->not->toBeNull();

    app(PageStore::class)->prune(now()->getTimestamp() + 300);

    expect(cacheSetAside('/front/'))->toBeNull()
        ->and(DB::table('page_cache_tags')->count())->toBe(0);
});

it('finds the addresses of the pages that carry any of these tags and answered 200, fresh or set aside, for warming', function (): void {
    cachePage('/harbour/', tags: ['content:ferry']);
    cachePage('/ferries/', tags: ['list:ferries']);
    cachePage('/tides/', tags: ['content:tides']);
    app(PageStore::class)->put(new PageEntry('http://localhost/gone/', 404, [], '<p>Not found</p>', now()->getTimestamp(), now()->getTimestamp() + 10, ['content:ferry']));
    app(PageStore::class)->markStale(['list:ferries'], now()->getTimestamp() + 300);

    expect(app(PageStore::class)->keys(['content:ferry', 'list:ferries'], 10))->toEqualCanonicalizing(['http://localhost/harbour/', 'http://localhost/ferries/'])
        ->and(app(PageStore::class)->keys(['list:ferries', 'content:tides'], 1))->toHaveCount(1)
        ->and(app(PageStore::class)->keys([], 10))->toBe([]);
});
