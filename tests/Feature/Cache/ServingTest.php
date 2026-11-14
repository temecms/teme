<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageEntry;
use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageStore;
use App\Http\Site\Middleware\CachePublicPages;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('answers the second request for a page from the cache, as the first was drawn', function (): void {
    pageArticle($this->author, 'Big story');

    $miss = addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $hit = addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    expect($hit->getContent())->toBe($miss->getContent())
        ->and($hit->headers->get('Content-Type'))->toBe($miss->headers->get('Content-Type'))
        ->and(cacheEntry('/big-story/')?->status)->toBe(200);
});

it('does nothing at all when it is off', function (): void {
    config(['page_cache.enabled' => false]);
    pageArticle($this->author, 'Big story');

    addrGet('/big-story/')->assertOk()->assertHeaderMissing('X-Teme-Cache');
    addrGet('/big-story/')->assertOk()->assertHeaderMissing('X-Teme-Cache');

    expect(DB::table('page_cache_tags')->count())->toBe(0)
        ->and(is_dir($this->folder))->toBeFalse();
});

it('lets a shared cache keep a page no longer than it has left, and has browsers ask again', function (): void {
    pageArticle($this->author, 'Big story');

    addrGet('/big-story/')->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=60')->assertHeader('Age', '0');

    $this->travel(5)->seconds();

    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'HIT')
        ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=55')
        ->assertHeader('Age', '5');
});

it('names the key, the tags and the seconds left, outside production only', function (): void {
    $story = pageArticle($this->author, 'Big story');
    $tag = (string) PageKey::of(Request::create('/big-story/'))?->urlTag();

    $response = addrGet('/big-story/')->assertHeader('X-Teme-Cache-Key', 'http://localhost/big-story/')
        ->assertHeader('X-Teme-Cache-Ttl', '60');

    expect(explode(' ', (string) $response->headers->get('X-Teme-Cache-Tags')))->toBe(cacheEntry('/big-story/')?->tags)
        ->toContain($tag, 'content:'.$story->id);

    // isProduction() reads $app['env'], not config('app.env').
    $this->app['env'] = 'production';

    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'HIT')
        ->assertHeaderMissing('X-Teme-Cache-Key')
        ->assertHeaderMissing('X-Teme-Cache-Tags')
        ->assertHeaderMissing('X-Teme-Cache-Ttl');
});

it('reads nothing from the database to answer from the cache', function (): void {
    pageArticle($this->author, 'Big story');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');

    DB::enableQueryLog();
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'HIT');

    expect(DB::getQueryLog())->toBe([]);
});

it('shares a page among addresses that differ only in parameters no page reads', function (): void {
    pageArticle($this->author, 'Big story');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');

    addrGet('/big-story/?utm_source=newsletter&fbclid=abc123&ref=home')->assertOk()
        ->assertHeader('X-Teme-Cache', 'HIT')
        ->assertHeader('X-Teme-Cache-Key', 'http://localhost/big-story/');
});

it('keeps a search, even an empty one, apart from the front page', function (): void {
    // In production, where a page's own robots header is the only one (I-291).
    $this->app['env'] = 'production';

    addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    addrGet('/?s=')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/?s=')->assertHeader('X-Teme-Cache', 'HIT')->assertHeader('X-Robots-Tag', 'noindex, follow');

    expect(cacheEntry('/?s=')?->key)->toBe('http://localhost/?s=');
});

it('still answers an address in capitals with its redirect once the page is stored (D2)', function (): void {
    pageArticle($this->author, 'About');
    addrGet('/about/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    $response = addrGet('/About/')->assertStatus(301);

    expect(addrLocation($response))->toBe('/about/');
});

it('answers a stored search without spending the reader\'s search limit', function (): void {
    searchPublished($this->author, 'Harbour notes', 'The lifeboat crew launched at dawn.');
    addrGet('/?s=lifeboat')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    // Thirty searches a minute is the limit (1.6h); these are hits, not searches.
    foreach (range(1, 31) as $n) {
        addrGet('/?s=lifeboat')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');
    }
});

it('stores only what a GET drew, and answers a HEAD from a stored page', function (): void {
    pageArticle($this->author, 'Big story');

    cacheGet('/big-story/', method: 'HEAD')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (head)');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');

    $head = cacheGet('/big-story/', method: 'HEAD')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    expect($head->getContent())->toBe('');
});

it('passes every method but GET and HEAD by', function (): void {
    expect(PageCache::bypass(Request::create('/big-story/', 'POST')))->toBe('method')
        ->and(PageCache::bypass(Request::create('/big-story/', 'GET')))->toBeNull()
        ->and(PageCache::bypass(Request::create('/big-story/', 'HEAD')))->toBeNull();
});

it('serves a stored page while the site is down for maintenance, and nothing else (D4)', function (): void {
    pageArticle($this->author, 'Big story');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');

    $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
    {
        public function activate(array $payload): void {}

        public function deactivate(): void {}

        public function active(): bool
        {
            return true;
        }

        public function data(): array
        {
            return [];
        }
    });

    addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');
    addrGet('/no-such-page/')->assertStatus(503);
});

it('looks pages up after the proxies are trusted and before maintenance mode, keeping Laravel\'s own middleware', function (): void {
    $global = app(HttpKernel::class)->getGlobalMiddleware();
    $at = array_search(CachePublicPages::class, $global, true);

    expect($at)->toBeInt()
        ->and(array_search(TrustProxies::class, $global, true))->toBeLessThan($at)
        ->and(array_search(PreventRequestsDuringMaintenance::class, $global, true))->toBeGreaterThan($at)
        ->and(array_values(array_diff((new Middleware)->getGlobalMiddleware(), $global)))->toBe([]);
});

it('keeps the public group free of sessions, cookies and CSRF, so its pages can be shared (I-262)', function (): void {
    // From the kernel, which copies its groups to the router only once a request runs.
    $public = app(HttpKernel::class)->getMiddlewareGroups()['public'];

    expect(array_values(array_intersect($public, [
        EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class,
    ])))->toBe([]);
});

it('never lets a failing store break a page', function (): void {
    Exceptions::fake();
    $this->app->bind(PageStore::class, fn (): PageStore => new class implements PageStore
    {
        public function get(PageKey $key): ?PageEntry
        {
            throw new RuntimeException('The page cache folder is gone.');
        }

        public function put(PageEntry $entry): void
        {
            throw new RuntimeException('The page cache folder is gone.');
        }

        public function stale(PageKey $key): ?PageEntry
        {
            return null;
        }

        public function markStale(array $tags, int $until): int
        {
            return 0;
        }

        public function purge(array $tags): int
        {
            return 0;
        }

        public function forget(string $key): void {}

        public function flush(): int
        {
            return 0;
        }

        public function prune(int $now): int
        {
            return 0;
        }

        public function lock(PageKey $key, int $seconds): Lock
        {
            throw new RuntimeException('The page cache folder is gone.');
        }

        public function keys(array $tags, int $limit): array
        {
            return [];
        }
    });
    pageArticle($this->author, 'Big story');

    addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (store failed)');

    Exceptions::assertReported(RuntimeException::class);
});

it('takes a stored page it can\'t read for a miss, and stores it again', function (): void {
    pageArticle($this->author, 'Big story');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');
    file_put_contents(app(PageStore::class)->pathOf('http://localhost/big-story/'), "not a page\n<p>Nothing</p>");

    addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'HIT');
});
