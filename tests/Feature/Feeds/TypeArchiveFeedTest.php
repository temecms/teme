<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\RenderRecord;
use App\Domain\Feeds\FeedBuilder;
use App\Domain\Feeds\FeedSubject;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\Operations\UpdateType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->admin = ctAdmin();
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    app(PathProjection::class)->syncUser($this->author);
    ltTypes();
});

it('serves Films’ feed at /films/feed/: its newest films alone, titled with its plural (I-299)', function (): void {
    ltItem($this->author, 'post', 'Harbour story');
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Arrival');
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Dune');
    $root = rtrim(url('/'), '/');
    $feed = feedDom('/films/feed/');

    expect($feed->evaluate('string(/rss/channel/title)'))->toBe('Films – '.pageSiteName())
        ->and($feed->evaluate('string(/rss/channel/link)'))->toBe($root.'/films/')
        ->and($feed->evaluate('string(/rss/channel/atom:link/@href)'))->toBe($root.'/films/feed/')
        ->and($feed->evaluate('count(//item)'))->toBe(2.0)
        ->and($feed->evaluate('string(//item[1]/title)'))->toBe('Dune')
        ->and($feed->evaluate('string(//item[2]/title)'))->toBe('Arrival');
});

it('sends every other spelling of its feed to /films/feed/', function (string $from): void {
    expect(addrLocation(addrGet($from)->assertStatus(301)))->toBe('/films/feed/');
})->with(['/films/feed', '/films/rss/', '/films/feed/atom/', '/Films/feed/']);

it('sends the feed at an old address to the feed at the new one (I-412)', function (): void {
    archMove($this->admin, ['film' => 'movies']);

    expect(addrLocation(addrGet('/films/feed/')->assertStatus(301)))->toBe('/movies/feed/');

    feedDom('/movies/feed/');
});

it('answers 404 for the feed while Films’ archive is ticked off', function (): void {
    scElevated(fn () => app(UpdateType::class)->execute(new UpdateTypeInput(ctDefinition(['archive' => false]))));

    addrGet('/films/feed/')->assertNotFound();
});

it('links its feed from the archive page’s head, titled as WordPress titles a post type’s', function (): void {
    $dom = dtDom((string) addrGet('/films/')->assertOk()->getContent());
    $links = '//head/link[@rel="alternate"][@type="application/rss+xml"]';
    $site = pageSiteName();

    expect((int) $dom->evaluate("count({$links})"))->toBe(2)
        ->and($dom->evaluate("string({$links}[2]/@href)"))->toBe(rtrim(url('/'), '/').'/films/feed/')
        ->and($dom->evaluate("string({$links}[2]/@title)"))->toBe("{$site} » Films Feed");
});

it('records the films list for the page cache, and no other type’s (I-263)', function (): void {
    app(RenderRecord::class)->start(0);

    app(FeedBuilder::class)->build(new FeedSubject(FeedSubject::TYPE, 'Films', 'films', null, null, null, null, 'film'));

    expect(app(RenderRecord::class)->tags())->toContain('feed', 'list:film')->not->toContain('list:post');
});
