<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageEntry;
use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageStore;
use App\Domain\Cache\PageTags;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

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
    // A request that waits sleeps on Carbon's clock, never for real.
    Sleep::fake(syncWithCarbon: true);
});

afterEach(fn () => cacheOff($this->folder));

it('holds a lock no longer than a page may take to draw and still be stored, and waits less than a lock lasts (D3)', function (): void {
    expect(PageCache::LOCK_SECONDS)->toBeLessThanOrEqual(PageCache::RENDER_WINDOW)
        ->and(PageCache::LOCK_WAIT)->toBeLessThan(PageCache::LOCK_SECONDS);
});

it('answers a request from the page another request was drawing, once that one stores it', function (): void {
    pageArticle($this->author, 'Big story');
    cacheDrawing('/big-story/');

    // The other request stores its page while this one waits.
    Sleep::whenFakingSleep(function (): void {
        if (cacheEntry('/big-story/') === null) {
            cachePage('/big-story/', '<p>Drawn by the other request</p>');
        }
    });

    addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'HIT')->assertSee('Drawn by the other request');

    Sleep::assertSleptTimes(1);
});

it('waits no longer than LOCK_WAIT for a page another request is drawing, then draws it itself', function (): void {
    pageArticle($this->author, 'Big story');
    cacheDrawing('/big-story/');

    addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->assertSee('Big story');

    Sleep::assertSleptTimes(intdiv(PageCache::LOCK_WAIT * 1000, PageCache::WAIT_STEP));
});

it('serves a list\'s copy set aside, marked STALE and kept by no shared cache, while another request draws it afresh', function (): void {
    cachePage('/', '<p>The front page as it was</p>', tags: [PageTags::url('/'), 'list:post']);
    $this->travel(3)->seconds();
    app(PageCache::class)->invalidate(['list:post']);
    cacheDrawing('/');

    addrGet('/')->assertOk()
        ->assertHeader('X-Teme-Cache', 'STALE')
        ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=0')
        ->assertHeader('Age', '3')
        ->assertSee('The front page as it was');

    Sleep::assertNeverSlept();
});

it('serves a page whose life has ended STALE while another request draws it afresh', function (): void {
    cachePage('/big-story/', '<p>The story as it was</p>', lifetime: 60);
    $this->travel(61)->seconds();
    cacheDrawing('/big-story/');

    addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'STALE')->assertHeader('Age', '61')->assertSee('The story as it was');

    Sleep::assertNeverSlept();
});

it('never serves stale a page that showed what changed: it waits, then draws the page', function (): void {
    $story = pageArticle($this->author, 'Big story');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');
    pageSaveDraft($story, 'Big story, corrected');
    listsPublish($story, $this->author);
    cacheDrawing('/big-story/');

    expect(addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->getContent())
        ->toContain('<title>Big story, corrected – ');

    Sleep::assertSleptTimes(intdiv(PageCache::LOCK_WAIT * 1000, PageCache::WAIT_STEP));
});

it('looks again inside the lock, and answers from a page stored between the first look and the lock', function (): void {
    pageArticle($this->author, 'Big story');
    cachePage('/big-story/', '<p>Stored meanwhile</p>');
    $files = app(PageStore::class);

    // The first look finds nothing, as if the page were stored just after it.
    $this->app->bind(PageStore::class, fn (): PageStore => new class($files) implements PageStore
    {
        private int $looks = 0;

        public function __construct(private readonly PageStore $files) {}

        public function get(PageKey $key): ?PageEntry
        {
            return ++$this->looks === 1 ? null : $this->files->get($key);
        }

        public function stale(PageKey $key): ?PageEntry
        {
            return $this->files->stale($key);
        }

        public function put(PageEntry $entry): void
        {
            $this->files->put($entry);
        }

        public function purge(array $tags): int
        {
            return $this->files->purge($tags);
        }

        public function markStale(array $tags, int $until): int
        {
            return $this->files->markStale($tags, $until);
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

    // Drawn, the page would carry X-Teme-Template; the page cachePage() stored doesn't.
    addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'HIT')->assertSee('Stored meanwhile')->assertHeaderMissing('X-Teme-Template');
});

it('frees the lock once the page is drawn', function (): void {
    pageArticle($this->author, 'Big story');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');

    expect(Cache::lock((string) PageKey::of(Request::create('/big-story/'))?->lockName(), 1)->get())->toBeTrue();
});

it('gives a HEAD neither a lock nor a wait: its page is never stored', function (): void {
    pageArticle($this->author, 'Big story');
    cacheDrawing('/big-story/');

    cacheGet('/big-story/', method: 'HEAD')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (head)');

    Sleep::assertNeverSlept();
});

it('never looks up, locks or waits for an address the router answers itself (D4)', function (): void {
    cacheDrawing('/up');

    cacheGet('/up')->assertOk()->assertHeaderMissing('X-Teme-Cache');

    Sleep::assertNeverSlept();
    expect(PageCache::bypass(Request::create('/admin/login')))->toBe('reserved')
        ->and(PageCache::bypass(Request::create('/up')))->toBe('reserved')
        // The router matches /admin in lower case only; /Admin/ is the public site's to answer.
        ->and(PageCache::bypass(Request::create('/Admin/')))->toBeNull();
});

it('takes each page\'s lock from the store that keeps its pages, so servers that share the store share its locks', function (): void {
    pageArticle($this->author, 'Big story');
    $files = app(PageStore::class);
    $asked = new ArrayObject;

    $this->app->bind(PageStore::class, fn (): PageStore => new class($files, $asked) implements PageStore
    {
        /**
         * @param  ArrayObject<int, string>  $asked
         */
        public function __construct(private readonly PageStore $files, private readonly ArrayObject $asked) {}

        public function lock(PageKey $key, int $seconds): Lock
        {
            $this->asked[] = $key->lockName();

            return $this->files->lock($key, $seconds);
        }

        public function keys(array $tags, int $limit): array
        {
            return [];
        }

        public function get(PageKey $key): ?PageEntry
        {
            return $this->files->get($key);
        }

        public function stale(PageKey $key): ?PageEntry
        {
            return $this->files->stale($key);
        }

        public function put(PageEntry $entry): void
        {
            $this->files->put($entry);
        }

        public function purge(array $tags): int
        {
            return $this->files->purge($tags);
        }

        public function markStale(array $tags, int $until): int
        {
            return $this->files->markStale($tags, $until);
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
    });

    addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    expect($asked->getArrayCopy())->toBe([(string) PageKey::of(Request::create('/big-story/'))?->lockName()]);
});
