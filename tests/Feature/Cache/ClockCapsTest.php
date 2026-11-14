<?php

declare(strict_types=1);

use App\Domain\Cache\ClockChanges;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Mid-year, so the new year is far off unless a test says otherwise.
    $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC'));
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->folder = cacheOn();
    config(['page_cache.lifetime' => 3600]);
});

afterEach(fn () => cacheOff($this->folder));

it('never keeps a page past the moment an item dated ahead comes into view (I-266)', function (): void {
    pageArticle($this->author, 'Harbour news');
    $ferries = pageArticle($this->author, 'Ferry timetable');
    cacheDateAhead($ferries, $this->author, 90);

    addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->assertHeader('X-Teme-Cache-Ttl', '90');

    // The latest posts leave it out until its date, then list it. The good
    // fixture's front template draws no list, so this reads what the page
    // recorded, as StalenessTest does.
    expect(cacheEntry('/')?->tags)->not->toContain('content:'.$ferries->id);

    $this->travel(90)->seconds();
    addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    expect(cacheEntry('/')?->tags)->toContain('content:'.$ferries->id);
});

it('keeps the "not found" page at an item dated ahead no longer than until the item appears there', function (): void {
    $ferries = pageArticle($this->author, 'Ferry timetable');
    cacheDateAhead($ferries, $this->author, 5);

    addrGet('/ferry-timetable/')->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS')->assertHeader('X-Teme-Cache-Ttl', '5');

    $this->travel(5)->seconds();

    addrGet('/ferry-timetable/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
});

it('never keeps a page past the new year on the site\'s clock, which every footer shows', function (): void {
    lscrTimezone('Africa/Lagos');
    // One minute to midnight in Lagos (UTC+1), an hour and a minute before UTC's.
    $this->travelTo(CarbonImmutable::parse('2026-12-31 23:59:00', 'Africa/Lagos'));
    pageArticle($this->author, 'Harbour news');

    addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS')->assertHeader('X-Teme-Cache-Ttl', '60');
});

it('lets a page live its whole life when nothing changes by the clock before then', function (): void {
    pageArticle($this->author, 'Harbour news');

    addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS')->assertHeader('X-Teme-Cache-Ttl', '3600');
});

it('finds the next item dated ahead through contents_published_order, even in a generic plan (1.6h)', function (): void {
    planFillerPosts($this->author);

    // A generic plan can't prove a bound value meets the index's condition:
    // the statement writes that condition itself.
    $plan = genericPlanOf(fn () => app(ClockChanges::class)->next(now()->getTimestamp()), 'min(');

    expect($plan)->toContain('contents_published_order');
});
