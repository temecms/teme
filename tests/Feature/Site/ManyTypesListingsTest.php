<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\ListingTypes;
use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\RenderRecord;
use App\Domain\Site\LatestViews;
use App\Domain\Site\ListingViews;
use App\Domain\Site\SiteDates;
use App\Domain\Site\ViewModels\ListingItem;
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
    $this->news = typesTerm($this->admin, 'category', 'News');
    $this->quick = typesTerm($this->admin, 'tag', 'Quick');
});

it('lists a category’s posts and films together, newest first, and counts both for its pages (I-3298)', function (): void {
    cmSettings(['posts_per_page' => 1], 'reading');
    ltItem($this->author, 'post', 'Harbour story', [$this->news]);
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Arrival', [$this->news]);
    $this->travel(1)->minutes();
    ltItem($this->author, 'post', 'Unfiled story');

    $first = app(ListingViews::class)->forTerm($this->news->refresh(), 1);

    expect(ltTitles($first->items))->toBe(['Arrival'])
        ->and(ltTitles(app(ListingViews::class)->forTerm($this->news->refresh(), 2)->items))->toBe(['Harbour story'])
        ->and($first->pagination->total)->toBe(2);
});

it('lists a tag’s recipes beside its posts, though Recipes have no archive: a term lists every public type filed under it', function (): void {
    ltItem($this->author, 'post', 'Quick supper', [$this->quick]);
    $this->travel(1)->minutes();
    ltItem($this->author, 'recipe', 'Fish stew', [$this->quick]);

    expect(ltTitles(app(ListingViews::class)->forTerm($this->quick->refresh(), 1)->items))->toBe(['Fish stew', 'Quick supper']);
});

it('lists an author’s posts and films, never their recipes, and counts both for the pages', function (): void {
    cmSettings(['posts_per_page' => 1], 'reading');
    ltItem($this->author, 'post', 'Harbour story');
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Arrival');
    $this->travel(1)->minutes();
    ltItem($this->author, 'recipe', 'Fish stew');
    $page = fn (int $number) => app(ListingViews::class)->forAuthor($this->author, $number);

    expect(ltTitles($page(1)->items))->toBe(['Arrival'])
        ->and(ltTitles($page(2)->items))->toBe(['Harbour story'])
        ->and($page(1)->pagination->total)->toBe(2);
});

it('lists a year’s posts and films, never its recipes', function (): void {
    ltItem($this->author, 'post', 'Harbour story');
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Arrival');
    ltItem($this->author, 'recipe', 'Fish stew');

    expect(ltTitles(app(ListingViews::class)->forDate(SiteDates::inSite('now')->year, null, null, 1)->items))->toBe(['Arrival', 'Harbour story']);
});

it('shows posts alone on the home page until Reading ticks Films, then both, newest first (I-3298)', function (): void {
    ltItem($this->author, 'post', 'Harbour story');
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Arrival');
    $this->travel(1)->minutes();
    ltItem($this->author, 'recipe', 'Fish stew');
    $shown = function (): array {
        $latest = app(LatestViews::class)->latest(1);

        return array_values(array_filter([$latest->lead?->title, ...ltTitles($latest->feed->items)]));
    };

    expect($shown())->toBe(['Harbour story']);

    cmSettings([ListingTypes::SETTING => ['post', 'film']], 'reading');

    expect($shown())->toBe(['Arrival', 'Harbour story']);
});

it('lists the ticked types on the posts page too, when the home page is a page', function (): void {
    $welcome = addrPublished($this->admin, 'Welcome', 'page');
    $latest = addrPublished($this->admin, 'Latest', 'page');
    addrChange($this->admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $welcome->id, 'postsPageId' => $latest->id]);
    ltItem($this->author, 'post', 'Harbour story');
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Arrival');
    cmSettings([ListingTypes::SETTING => ['film']], 'reading');

    $view = app(LatestViews::class)->latest(1, $latest);

    expect($view->lead?->title)->toBe('Arrival')
        ->and($view->feed->items)->toBe([])
        ->and($view->feed->subject?->title)->toBe('Latest');
});

it('pins only posts, whose type supports it, ahead of a newer film', function (): void {
    pageArticle($this->author, 'Pinned story', sticky: true);
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Arrival');
    cmSettings([ListingTypes::SETTING => ['post', 'film']], 'reading');

    $view = app(LatestViews::class)->latest(1);

    expect($view->lead?->title)->toBe('Pinned story')
        ->and(ltTitles($view->feed->items))->toBe(['Arrival']);
});

it('gives each card its own type’s trailing slash, and the listing its address as before', function (): void {
    addrChange($this->admin, ['patterns' => ['film' => '/films/{slug}']]);
    ltItem($this->author, 'post', 'Harbour story', [$this->news]);
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Arrival', [$this->news]);

    $view = app(ListingViews::class)->forTerm($this->news->refresh(), 1);

    expect(array_map(fn (ListingItem $item): string => $item->url, $view->items))->toBe(['/films/arrival', '/harbour-story/'])
        ->and($view->subject?->url)->toBe('/category/news/');
});

it('records each ticked type’s list for the page cache on the home page, and no other type’s (I-263)', function (): void {
    cmSettings([ListingTypes::SETTING => ['post', 'film']], 'reading');
    app(RenderRecord::class)->start(0);

    app(LatestViews::class)->latest(1);

    expect(app(RenderRecord::class)->tags())->toContain('list:post', 'list:film')
        ->not->toContain('list:recipe');
});

// Each comparison measures one page, then adds types to that same page and
// measures it again: a statement per type would show as a difference.
it('reads as many statements for a category once films and documentaries join its posts', function (): void {
    $mixed = typesTerm($this->admin, 'category', 'Mixed');

    foreach (range(1, 3) as $n) {
        ltItem($this->author, 'post', "Story {$n}", [$mixed]);
    }

    $before = cardsStatements('/category/mixed/');

    ltDocumentaries();
    ltItem($this->author, 'film', 'Mixed film', [$mixed]);
    ltItem($this->author, 'documentary', 'Mixed documentary', [$mixed]);

    expect(cardsStatements('/category/mixed/'))->toBe($before)
        ->and(addrGet('/category/mixed/')->getContent())->toContain('Mixed film', 'Mixed documentary');
});

it('reads as many statements for the home page once Reading ticks films and documentaries beside posts', function (): void {
    foreach (range(1, 3) as $n) {
        $this->travel(1)->minutes();
        ltItem($this->author, 'post', "Story {$n}");
    }

    $before = cardsStatements('/');

    ltDocumentaries();

    foreach (['film', 'documentary'] as $type) {
        $this->travel(1)->minutes();
        ltItem($this->author, $type, "Newest {$type}");
    }

    cmSettings([ListingTypes::SETTING => ['post', 'film', 'documentary']], 'reading');

    expect(cardsStatements('/'))->toBe($before)
        ->and(addrGet('/')->getContent())->toContain('Newest film', 'Newest documentary');
});

it('reads as many statements for an author once their films and documentaries join their posts', function (): void {
    $other = User::factory()->create(['name' => 'Bo Writer'])->assignRole('author');
    app(PathProjection::class)->syncUser($other);
    $url = Addresses::toUrl((string) addrCurrent('user', (string) $other->id));

    foreach (range(1, 3) as $n) {
        ltItem($other, 'post', "Work {$n}");
    }

    $before = cardsStatements($url);

    ltDocumentaries();
    ltItem($other, 'film', 'Their film');
    ltItem($other, 'documentary', 'Their documentary');

    expect(cardsStatements($url))->toBe($before)
        ->and(addrGet($url)->getContent())->toContain('Their film', 'Their documentary');
});
