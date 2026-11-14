<?php

declare(strict_types=1);

use App\Domain\Cache\Jobs\PurgeCdn;
use App\Domain\Cache\Jobs\WarmPages;
use App\Domain\Cache\PageStore;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\PageWarmer;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\Operator;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Cache\CacheKeysFor;

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

it('queues the pages a change removes, then those it sets aside, the home page first, and never a search, a list\'s later page or a "not found" page (D9)', function (): void {
    cacheShown([
        '/harbour/' => ['content:ferry'],
        '/?s=ferry' => ['content:ferry'],
        '/news/' => ['list:post'],
        '/news/page/2/' => ['list:post'],
        '/' => ['list:post'],
    ]);
    addrGet('/ferry-timetable/')->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS');
    cacheWarmOn();

    cacheProbe(new CacheKeysFor(CacheKeysFor::CONTENT, 'ferry', ['content:ferry', 'list:post', PageTags::url('/ferry-timetable/')]))->execute(schedProbeInput());

    Queue::assertPushed(WarmPages::class, 1);
    Queue::assertPushed(WarmPages::class, fn (WarmPages $job): bool => $job->urls === ['http://localhost/', 'http://localhost/harbour/', 'http://localhost/news/']);
});

it('draws those pages again on the queue, and only then starts the CDN\'s purge: regenerate first (D8)', function (): void {
    $story = pageArticle($this->author, 'Big story');
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/')->assertHeader('X-Teme-Cache', 'MISS');
    cacheWarmOn();
    cacheCdnOn();

    // An edit to the story: its page, and the front page, which shows its card, go.
    cacheProbe(new CacheKeysFor(CacheKeysFor::CONTENT, $story->id, ['content:'.$story->id]))->execute(schedProbeInput());

    expect(cacheKept('/big-story/', '/'))->toBe([]);
    Queue::assertNotPushed(PurgeCdn::class);

    app()->call([Queue::pushed(WarmPages::class)->first(), 'handle']);

    expect(cacheKept('/big-story/', '/'))->toBe(['/big-story/', '/']);
    Queue::assertPushed(PurgeCdn::class);
});

it('draws nothing inside the request that made the change: never on the sync queue (D10)', function (): void {
    Queue::fake();
    cacheShown(['/harbour/' => ['content:ferry']]);

    cacheProbe(new CacheKeysFor(CacheKeysFor::CONTENT, 'ferry', ['content:ferry']))->execute(schedProbeInput());

    Queue::assertNothingPushed();
    expect(cacheKept('/harbour/'))->toBe([]);
});

it('draws each page in a fresh scope, so a change another process made is read, never a value the page before remembered (D7)', function (): void {
    $warmer = app(PageWarmer::class);

    expect($warmer->warm('http://localhost/'))->toBe('MISS');

    // Another process renames the site: the shared cache store forgets the
    // group and its pages go, but this process's memo still holds the old name.
    DB::table('settings')->updateOrInsert(['group_key' => 'general'], ['overrides' => json_encode(['site_title' => 'Harbour Times'])]);
    Cache::forget(SettingsRepository::cacheKey('general'));
    app(PageStore::class)->purge([PageTags::SETTINGS]);

    expect($warmer->warm('http://localhost/'))->toBe('MISS')
        ->and(cacheEntry('/')?->body)->toContain('data-site-name="Harbour Times"');
});

it('gives the command its operator back after drawing a page, so a deploy that warms can still record itself', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $during = null;
    Event::listen(RequestHandled::class, function () use (&$during): void {
        $during = Operator::isPresent();
    });

    expect(app(PageWarmer::class)->warm('http://localhost/'))->toBe('MISS')
        // The page itself is drawn as a visitor's, never with the command's role.
        ->and($during)->toBeFalse()
        ->and(Operator::isPresent())->toBeTrue();
});
