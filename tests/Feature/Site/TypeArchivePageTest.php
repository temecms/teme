<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\RenderRecord;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Site\ListingViews;
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
    $this->news = typesTerm($this->admin, 'category', 'News');
});

it('serves Films’ archive page at /films/: every published film, newest first, under its plural (I-3304)', function (): void {
    ltItem($this->author, 'post', 'Harbour story', [$this->news]);
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Arrival', [$this->news]);
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Dune');
    ltItem($this->author, 'recipe', 'Fish stew');
    lifecycleDraft($this->author, 'Not yet', 'film');

    $response = addrGet('/films/')->assertOk()->assertHeader('X-Teme-Address', 'type-archive');

    expect(headValue(dtDom((string) $response->getContent()), '//main//h1'))->toBe('Films')
        ->and(archTitles('/films/'))->toBe(['Dune', 'Arrival']);
});

it('pages as every listing does, sending page 1 to the address and answering 404 past the last', function (): void {
    cmSettings(['posts_per_page' => 1], 'reading');
    ltItem($this->author, 'film', 'Arrival');
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Dune');

    expect(archTitles('/films/'))->toBe(['Dune'])
        ->and(archTitles('/films/page/2/'))->toBe(['Arrival'])
        ->and(addrLocation(addrGet('/films/page/1/')->assertStatus(301)))->toBe('/films/');

    addrGet('/films/page/3/')->assertNotFound();
});

it('answers its first page while Films has nothing published, never a 404', function (): void {
    addrGet('/films/')->assertOk()->assertSee('Nothing here yet.');
});

it('sends every other spelling of its address to the one it has', function (string $from, string $to): void {
    expect(addrLocation(addrGet($from)->assertStatus(301)))->toBe($to);
})->with([
    'no trailing slash' => ['/films', '/films/'],
    'capitals' => ['/Films/', '/films/'],
    'a later page without its slash' => ['/films/page/2', '/films/page/2/'],
    'a query, kept' => ['/films?ref=menu', '/films/?ref=menu'],
    'an encoded letter, never served' => ['/fil%6Ds/', '/films/'],
]);

it('sends its old address, and the old address’s later pages, to the new one once it moves (I-412)', function (): void {
    archMove($this->admin, ['film' => 'movies']);

    expect(addrLocation(addrGet('/films/')->assertStatus(301)))->toBe('/movies/')
        ->and(addrLocation(addrGet('/films/page/2/')->assertStatus(301)))->toBe('/movies/page/2/');

    addrGet('/movies/')->assertOk()->assertHeader('X-Teme-Address', 'type-archive');
});

it('answers 404 while Films’ archive is ticked off, and serves the page again once it is ticked on', function (): void {
    $update = fn (bool $archive) => scElevated(fn () => app(UpdateType::class)->execute(new UpdateTypeInput(ctDefinition(['archive' => $archive]))));

    $update(false);
    addrGet('/films/')->assertNotFound();

    $update(true);
    addrGet('/films/')->assertOk()->assertHeader('X-Teme-Address', 'type-archive');
});

it('leaves an address to the page that had it first, and serves the archive page there once the page moves', function (): void {
    $page = archReelsAfterPage($this->admin);

    addrGet('/reels/')->assertOk()->assertHeader('X-Teme-Address', 'current');

    addrRename($page, 'our-reels', $this->admin);

    addrGet('/reels/')->assertOk()->assertHeader('X-Teme-Address', 'type-archive');
});

it('tries the theme’s archive-film template before its archive template', function (): void {
    addrGet('/films/')->assertOk()
        ->assertHeader('X-Teme-Template', 'theme::templates.archive')
        ->assertHeader('X-Teme-Template-Candidates', 'templates.archive-film (missing), templates.archive (exists)');
});

it('titles the page by Search and social’s archive row, its page from page 2, and hides it by that row’s box (I-853)', function (): void {
    cmSettings(['posts_per_page' => 1], 'reading');
    ltItem($this->author, 'film', 'Arrival');
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Dune');
    cmSettings(['title_separator' => '|'], SearchAndSocial::KEY);
    $site = pageSiteName();
    $robots = '//head/meta[@name="robots"]/@content';

    expect(headValue(headDom('/films/'), '//title'))->toBe("Films | {$site}")
        ->and(headValue(headDom('/films/page/2/'), '//title'))->toBe("Films | Page 2 of 2 | {$site}")
        ->and(headValue(headDom('/films/'), $robots))->toBe('max-snippet:-1, max-image-preview:large, max-video-preview:-1');

    cmSettings(['title_archive_film' => 'Every {title} {sep} {site}', 'hide_archive_film' => true], SearchAndSocial::KEY);

    expect(headValue(headDom('/films/'), '//title'))->toBe("Every Films | {$site}")
        ->and(headValue(headDom('/films/'), $robots))->toBe('noindex, follow');
});

it('records its type’s list for the page cache, and no other, under a subject of its own (I-263)', function (): void {
    app(RenderRecord::class)->start(0);

    $view = app(ListingViews::class)->forType('film', 'films', 1);

    expect(app(RenderRecord::class)->tags())->toContain('list:film')
        ->not->toContain('list:post')
        ->and($view->subject?->kind)->toBe('type')
        ->and($view->subject?->title)->toBe('Films')
        ->and($view->subject?->url)->toBe('/films/');
});

it('reads as many statements for three films as for one', function (): void {
    ltItem($this->author, 'film', 'Arrival', [$this->news]);
    $before = cardsStatements('/films/');

    ltItem($this->author, 'film', 'Dune', [$this->news]);
    ltItem($this->author, 'film', 'Heat');

    expect(cardsStatements('/films/'))->toBe($before)
        ->and(addrGet('/films/')->getContent())->toContain('Dune', 'Heat');
});
