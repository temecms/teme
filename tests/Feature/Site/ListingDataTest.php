<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Contents\Visibilities;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    cmSettings(['posts_per_page' => 2], 'reading');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    app(PathProjection::class)->syncUser($this->author);
    $this->news = typesTerm(ctAdmin(), 'category', 'News');
    app(PathProjection::class)->syncTerm($this->news);
    foreach (range(1, 5) as $n) {
        $this->travel(1)->minutes();
        ltItem($this->author, 'post', "Heron story {$n}", [$this->news]);
    }
    /** The titles on a page's data, and the data. */
    $this->data = fn (string $uri) => addrGet($uri.(str_contains($uri, '?') ? '&' : '?').'format=json');
    $this->titles = fn (array $items): array => array_column($items, 'title');
});

it('gives a term archive’s page 2 as the same items the page shows, in its order, with its pagination', function (): void {
    $html = archTitles('/category/news/page/2/');
    $data = ($this->data)('/category/news/page/2/')->assertOk();

    expect($data->headers->get('Content-Type'))->toContain('application/json')
        ->and(($this->titles)($data->json('items')))->toBe($html)
        ->and($html)->toBe(['Heron story 3', 'Heron story 2'])
        ->and($data->json('pagination'))->toBe(['current' => 2, 'total' => 3, 'previous' => '/category/news/?format=json', 'next' => '/category/news/page/3/?format=json'])
        ->and($data->json('subject.kind'))->toBe('term');
});

it('ends with a next address of null on the last page, and answers 404 as JSON past it', function (): void {
    $last = ($this->data)('/category/news/page/3/')->assertOk();

    expect($last->json('pagination.next'))->toBeNull()
        ->and($last->json('items'))->toHaveCount(1);

    ($this->data)('/category/news/page/4/')->assertNotFound()->assertJson(['error' => 'not_found']);
});

it('gives an author’s archive, the latest posts, a type archive, a date archive and a search as data too', function (string $uri): void {
    $html = archTitles($uri);
    $data = ($this->data)($uri)->assertOk();

    expect(($this->titles)($data->json('items')))->toBe($html)->not->toBe([]);
})->with([
    'the latest posts' => ['/page/2/'],
    'a search' => ['/?s=heron'],
    'an author' => ['/author/jane-doe/'],
]);

it('keeps the query on a search’s next address and gives page 2 of it', function (): void {
    $first = ($this->data)('/?s=heron')->assertOk();

    expect($first->json('pagination.next'))->toBe('/page/2/?s=heron&format=json');

    $second = ($this->data)('/page/2/?s=heron')->assertOk();

    expect(($this->titles)($second->json('items')))->toBe(archTitles('/page/2/?s=heron'));
});

it('never gives a private item or a draft, as the page never shows one', function (): void {
    Visibilities::change(ltItem($this->author, 'post', 'Zqprivate heron'), 'private', $this->author);
    lifecycleDraft($this->author, 'Zqdraft heron', 'post');

    $titles = [];

    foreach (['/', '/category/news/', '/?s=heron', '/author/jane-doe/'] as $uri) {
        $titles[] = ($this->titles)(($this->data)($uri)->json('items'));
    }

    expect(json_encode($titles))->not->toContain('Zqprivate')->not->toContain('Zqdraft');
});

it('gives each item the fields a script draws a card from', function (): void {
    $item = ($this->data)('/category/news/')->json('items.0');

    expect($item)->toHaveKeys(['title', 'url', 'type', 'standfirst', 'kicker', 'authors', 'published', 'publishedText', 'image'])
        ->and($item['url'])->toStartWith('/')
        ->and($item['authors'][0]['name'])->toBe('Jane Doe');
});

it('answers 404 as JSON for a page that is not a listing', function (): void {
    ($this->data)('/heron-story-1/')->assertNotFound()->assertJson(['error' => 'not_a_listing']);
});

it('keeps the marker through a redirect to the canonical address', function (): void {
    expect(addrLocation(($this->data)('/category/news/page/1/')->assertStatus(301)))->toBe('/category/news/?format=json');
});

it('gives only the language asked for', function (): void {
    LanguageSite::build();

    $french = array_column(($this->data)('/fr/category/actualites/')->assertOk()->json('items'), 'title');
    $english = array_column(($this->data)('/category/news/')->assertOk()->json('items'), 'title');

    expect($french)->toBe(['Bonjour'])->and($english)->not->toContain('Bonjour');
});

it('is stored by the page cache apart from the page, and purged with it', function (): void {
    $folder = cacheOn();
    $this->freezeSecond();

    cacheGet('/category/news/?format=json')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    cacheGet('/category/news/?format=json')->assertHeader('X-Teme-Cache', 'HIT');
    expect(cacheGet('/category/news/')->assertHeader('X-Teme-Cache', 'MISS')->headers->get('Content-Type'))->toContain('text/html');
    cacheGet('/category/news/?format=xml')->assertHeader('X-Teme-Cache', 'HIT');

    ltItem($this->author, 'post', 'Heron story 6', [$this->news]);

    expect(cacheGet('/category/news/?format=json')->assertHeader('X-Teme-Cache', 'MISS')->json('items.0.title'))->toBe('Heron story 6');

    cacheOff($folder);
});

it('gives a type’s archive page and a date archive as data', function (): void {
    ltTypes();
    ltItem($this->author, 'film', 'Arrival');
    $year = '/'.now()->setTimezone(config('app.timezone'))->format('Y').'/';

    expect(($this->titles)(($this->data)('/films/')->assertOk()->json('items')))->toBe(['Arrival'])
        ->and(($this->titles)(($this->data)($year)->assertOk()->json('items')))->toBe(archTitles($year))
        ->and(($this->data)('/films/')->json('subject.kind'))->toBe('type');
});
