<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageTags;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
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
});

afterEach(fn () => cacheOffValkey($this->prefix ?? null));

it('answers the second request for a page from Valkey, as the first was drawn, reading nothing from the database', function (): void {
    $this->prefix = cacheOnRedis();
    pageArticle($this->author, 'Big story');

    $miss = addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    DB::enableQueryLog();
    $hit = addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    expect($hit->getContent())->toBe($miss->getContent())
        ->and(DB::getQueryLog())->toBe([]);
});

it('removes from Valkey the page that showed what an Operation changed', function (): void {
    $this->prefix = cacheOnRedis();
    $story = pageArticle($this->author, 'Big story');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');

    pageSaveDraft($story, 'Big story, corrected');
    listsPublish($story, $this->author);

    expect(cacheEntry('/big-story/'))->toBeNull()
        ->and(addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->getContent())->toContain('<title>Big story, corrected – ');
});

it('gives a list\'s copy set aside in Valkey, STALE, while another request draws it, reading nothing from the database', function (): void {
    $this->prefix = cacheOnRedis();
    Sleep::fake(syncWithCarbon: true);
    cachePage('/', '<p>The front page as it was</p>', tags: [PageTags::url('/'), 'list:post']);
    $this->travel(3)->seconds();
    app(PageCache::class)->invalidate(['list:post']);
    cacheDrawing('/');

    DB::enableQueryLog();

    addrGet('/')->assertOk()
        ->assertHeader('X-Teme-Cache', 'STALE')
        ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=0')
        ->assertHeader('Age', '3')
        ->assertSee('The front page as it was');

    expect(DB::getQueryLog())->toBe([]);
    Sleep::assertNeverSlept();
});

it('prunes expired pages from Valkey, and clears it, with the commands', function (): void {
    $this->prefix = cacheOnRedis();
    cachePage('/harbour/', lifetime: 60);
    cachePage('/ferries/', lifetime: 600);
    $this->travel(61)->seconds();

    $this->artisan('teme:cache:prune')->expectsOutputToContain('Removed 1 expired page from the page cache.')->assertSuccessful();

    expect(cacheKept('/harbour/', '/ferries/'))->toBe(['/ferries/']);

    $this->artisan('teme:cache:clear')->expectsOutputToContain('Removed 1 page from the page cache.')->assertSuccessful();

    expect(cacheKept('/ferries/'))->toBe([]);
});

it('never lets a Valkey that doesn\'t answer break a page or an Operation', function (): void {
    Exceptions::fake();
    config([
        'page_cache.enabled' => true,
        'page_cache.lifetime' => 60,
        'page_cache.driver' => 'redis',
        'page_cache.stores.redis.prefix' => 'teme-test-unreachable:',
        'app.url' => 'http://localhost',
        // Nothing listens there, and a fifth of a second is long enough to know it.
        'database.redis.page-cache.port' => 1,
        'database.redis.page-cache.timeout' => 0.2,
    ]);
    pageArticle($this->author, 'Big story');

    addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (store failed)')->assertSee('Big story');

    Exceptions::assertReported(RedisException::class);
});

it('tries a Valkey that can\'t be connected to once for a missed page, not once for the lookup, the lock and the write', function (): void {
    Exceptions::fake();
    config([
        'page_cache.enabled' => true,
        'page_cache.lifetime' => 60,
        'page_cache.driver' => 'redis',
        'page_cache.stores.redis.prefix' => 'teme-test-one-attempt:',
        'app.url' => 'http://localhost',
    ]);
    // Every try at a connection costs what a connect timeout costs against an address that drops packets.
    $attempts = new class implements RedisFactory
    {
        public int $count = 0;

        public function connection($name = null): void
        {
            $this->count++;

            throw new RedisException('Connection timed out');
        }
    };
    $this->app->instance(RedisFactory::class, $attempts);
    pageArticle($this->author, 'Big story');

    addrGet('/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (store failed)')->assertSee('Big story');

    expect($attempts->count)->toBe(1);
    Exceptions::assertReported(RedisException::class);
});
