<?php

declare(strict_types=1);

use App\Domain\Cache\CdnPurges;
use App\Domain\Cache\Jobs\PurgeCdn;
use App\Domain\Status\Checks\PageCacheCdnPurges;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Cache\CacheKeysFor;
use Tests\Fixtures\Backups\HealthyBackups;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('purges Cloudflare by tag once a change commits, after Teme\'s own pages, and not at all after a rollback (rule 6)', function (): void {
    cacheCdnOn();
    cacheShown(['/shows-the-ferry/' => ['content:ferry'], '/lists-posts/' => ['list:post']]);
    $ferry = new CacheKeysFor(CacheKeysFor::CONTENT, 'ferry', ['content:ferry', 'list:post']);

    cacheRolledBack(fn () => cacheProbe($ferry)->execute(schedProbeInput()));

    expect(cacheCdnSent())->toBe([])
        ->and(DB::table('page_cache_cdn_purges')->count())->toBe(0);

    cacheProbe($ferry)->execute(schedProbeInput());

    expect(cacheKept('/shows-the-ferry/', '/lists-posts/'))->toBe([])
        ->and(cacheCdnSent())->toBe([['tags' => CdnPurges::keys(['content:ferry', 'list:post'])]])
        ->and(DB::table('page_cache_cdn_purges')->count())->toBe(0);
});

it('queues nothing for a CDN when none is configured', function (): void {
    cacheShown(['/shows-the-ferry/' => ['content:ferry']]);

    cacheProbe(new CacheKeysFor(CacheKeysFor::CONTENT, 'ferry', ['content:ferry']))->execute(schedProbeInput());

    expect(DB::table('page_cache_cdn_purges')->count())->toBe(0);
    Http::assertNothingSent();
});

it('gathers the tags into requests of a hundred, and waits once the plan\'s five a minute are spent (D4, D5)', function (): void {
    cacheCdnOn();
    $tags = array_map(fn (int $number): string => 'content:item-'.$number, range(1, 650));
    app(CdnPurges::class)->queue($tags);

    $drain = (new PurgeCdn)->withFakeQueueInteractions();
    $drain->handle(app(CdnPurges::class));

    $drain->assertReleased(60);
    expect(cacheCdnSent())->toHaveCount(5)
        ->and(array_merge(...array_column(cacheCdnSent(), 'tags')))->toBe(CdnPurges::keys(array_slice($tags, 0, 500)))
        ->and(DB::table('page_cache_cdn_purges')->count())->toBe(150);

    $this->travel(60)->seconds();
    $drain = (new PurgeCdn)->withFakeQueueInteractions();
    $drain->handle(app(CdnPurges::class));

    $drain->assertNotReleased();
    expect(cacheCdnSent())->toHaveCount(7)
        ->and(DB::table('page_cache_cdn_purges')->count())->toBe(0);
});

it('waits as long as Cloudflare says, and keeps what it asked to wait for', function (): void {
    cacheCdnOn(Http::response(['success' => false, 'errors' => [['code' => 971, 'message' => 'Please wait and consider throttling your request speed']]], 429, ['Retry-After' => '30']));
    app(CdnPurges::class)->queue(['content:ferry']);

    $drain = (new PurgeCdn)->withFakeQueueInteractions();
    $drain->handle(app(CdnPurges::class));

    $drain->assertReleased(30);
    expect(DB::table('page_cache_cdn_purges')->pluck('tag')->all())->toBe(['content:ferry'])
        ->and(Cache::get(CdnPurges::FAILED_KEY))->toBeNull();
});

it('reports a purge Cloudflare refuses, warns administrators, keeps the tags and tries again a minute later (D5)', function (): void {
    HealthyBackups::seed();

    Exceptions::fake();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $admin = User::factory()->create()->assignRole('administrator');
    cacheCdnOn(Http::sequence()
        ->push(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403)
        ->push(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['id' => 'purge-test']]));
    app(CdnPurges::class)->queue(['content:ferry']);

    $drain = (new PurgeCdn)->withFakeQueueInteractions();
    $drain->handle(app(CdnPurges::class));

    $drain->assertReleased(CdnPurges::RETRY);
    Exceptions::assertReported(fn (RuntimeException $failure): bool => str_contains($failure->getMessage(), 'Authentication error'));
    expect(DB::table('page_cache_cdn_purges')->count())->toBe(1)
        ->and(app(StatusChecks::class)->forUser($admin)?->message)
        ->toBe('Some changes can show up to a minute late: the CDN refused the last request to clear changed pages. Check the CDN setting (PAGE_CACHE_CDN) and its keys; the log says why.');

    $this->travel(CdnPurges::RETRY)->seconds();
    $drain = (new PurgeCdn)->withFakeQueueInteractions();
    $drain->handle(app(CdnPurges::class));

    $drain->assertNotReleased();
    // Its own check: the good fixture's unbound menu places keep a banner of their own (priority 50).
    expect(DB::table('page_cache_cdn_purges')->count())->toBe(0)
        ->and(app(PageCacheCdnPurges::class)->banner())->toBeNull();
});

it('sends a tag again when it is queued while its batch is out, since that change came after the purge (D4)', function (): void {
    $asked = 0;
    cacheCdnOn(function () use (&$asked) {
        // An editor changes the ferry again while the first request is out.
        if (++$asked === 1) {
            app(CdnPurges::class)->queue(['content:ferry']);
        }

        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['id' => 'purge-test']]);
    });
    app(CdnPurges::class)->queue(['content:ferry', 'content:tides']);

    (new PurgeCdn)->withFakeQueueInteractions()->handle(app(CdnPurges::class));

    expect(cacheCdnSent())->toBe([
        ['tags' => CdnPurges::keys(['content:ferry', 'content:tides'])],
        ['tags' => CdnPurges::keys(['content:ferry'])],
    ])->and(DB::table('page_cache_cdn_purges')->count())->toBe(0);
});

it('purges the whole site at Cloudflare by its host when every page goes, and with it everything queued before (D6)', function (): void {
    cacheCdnOn();
    app(CdnPurges::class)->queue(['content:ferry', 'list:post']);

    $this->artisan('teme:cache:clear')->assertSuccessful();

    expect(cacheCdnSent())->toBe([['hosts' => ['localhost']]])
        ->and(DB::table('page_cache_cdn_purges')->count())->toBe(0);
});

it('sends what waits for the CDN every minute, whatever stopped the last drain (D5)', function (): void {
    cacheCdnOn();
    // Queued by a change whose drain died with its worker.
    app(CdnPurges::class)->queue(['content:ferry']);

    $this->artisan('teme:cache:prune')->assertSuccessful();

    expect(cacheCdnSent())->toBe([['tags' => CdnPurges::keys(['content:ferry'])]]);
});

it('purges the whole site at the CDN once, when the cache is off and the prune first empties the store (D12)', function (): void {
    cacheCdnOn();
    cachePage('/harbour/');
    config(['page_cache.enabled' => false]);

    $this->artisan('teme:cache:prune')->assertSuccessful();
    $this->artisan('teme:cache:prune')->assertSuccessful();

    expect(cacheCdnSent())->toBe([['hosts' => ['localhost']]]);
});
