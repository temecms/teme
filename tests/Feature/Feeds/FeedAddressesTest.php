<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
    app(PathProjection::class)->syncUser($this->author);
    $this->news = typesTerm($this->editor, 'category', 'News');
    $this->story = pageArticle($this->author, 'Harbour news', categories: [$this->news]);
});

it('serves the site\'s feed at /feed/ as RSS 2.0', function (): void {
    $feed = feedDom('/feed/');

    expect($feed->evaluate('string(/rss/@version)'))->toBe('2.0')
        ->and($feed->evaluate('string(/rss/channel/atom:link/@href)'))->toBe(rtrim(url('/'), '/').'/feed/')
        ->and($feed->evaluate('count(//item)'))->toBe(1.0)
        ->and($feed->evaluate('string(//item/title)'))->toBe('Harbour news')
        ->and($feed->evaluate('string(//item/link)'))->toBe(rtrim(url('/'), '/').'/harbour-news/');
});

it('serves a category\'s feed and an author\'s at their addresses followed by /feed/', function (): void {
    pageArticle($this->author, 'Elsewhere');
    $category = feedDom('/category/news/feed/');
    $person = feedDom('/author/jane-doe/feed/');

    expect($category->evaluate('string(/rss/channel/title)'))->toBe('News – '.pageSiteName())
        ->and($category->evaluate('count(//item)'))->toBe(1.0)
        ->and($category->evaluate('string(/rss/channel/link)'))->toBe(rtrim(url('/'), '/').'/category/news/')
        ->and($person->evaluate('string(/rss/channel/title)'))->toBe('Jane Doe – '.pageSiteName())
        ->and($person->evaluate('count(//item)'))->toBe(2.0);
});

it('sends every other spelling of a feed address to the canonical one with a permanent redirect, and drops the query', function (string $from, string $to): void {
    expect(addrLocation(addrGet($from)->assertStatus(301)))->toBe($to);
})->with([
    'no trailing slash' => ['/feed', '/feed/'],
    'capitals' => ['/Feed/', '/feed/'],
    'the long form' => ['/feed/rss2/', '/feed/'],
    'Atom, whose format Teme does not write' => ['/feed/atom/', '/feed/'],
    'a bare name' => ['/rss/', '/feed/'],
    'a query' => ['/feed/atom/?utm=x', '/feed/'],
    'a category without its slash' => ['/category/news/feed', '/category/news/feed/'],
    'a category\'s Atom' => ['/category/news/atom/', '/category/news/feed/'],
    'an author\'s RDF in its long form' => ['/author/jane-doe/feed/rdf/', '/author/jane-doe/feed/'],
]);

it('answers a comment feed with 410 in plain text: an item\'s, its Atom spelling, and the site\'s (I-305)', function (string $path): void {
    $response = addrGet($path)->assertStatus(410)->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect($response->getContent())->toBe("Gone. This site has no feed of comments.\n");
})->with(['/harbour-news/feed/', '/harbour-news/feed/atom/', '/comments/feed/', '/comments/feed/rss2/']);

it('answers a comment feed at an item\'s old address with 410 too', function (): void {
    addrRename($this->story, 'harbour-views', $this->editor);

    addrGet('/harbour-news/feed/')->assertStatus(410);
    addrGet('/harbour-views/feed/')->assertStatus(410);
});

it('hands back an address that exists, so a page called rss or atom is still a page', function (): void {
    addrPublished($this->author, 'Rss');
    addrPublished($this->author, 'Atom');

    foreach (['/rss/', '/atom/'] as $path) {
        $response = addrGet($path)->assertOk();

        expect((string) $response->headers->get('Content-Type'))->toStartWith('text/html')
            ->and((string) $response->getContent())->not->toContain('<rss');
    }
});

it('hands back what is not a feed\'s to the public site: an unknown base, a date archive, an address the router answers itself', function (string $path): void {
    $response = addrGet($path)->assertNotFound();

    expect((string) $response->headers->get('Content-Type'))->toStartWith('text/html');
})->with(['/nowhere/feed/', '/nowhere/atom/', '/2026/10/feed/', '/build/feed/']);

it('sends an old address of an author to the current feed', function (): void {
    addrChange($this->admin, ['authorBase' => 'writers']);

    expect(addrLocation(addrGet('/author/jane-doe/feed/')->assertStatus(301)))->toBe('/writers/jane-doe/feed/')
        ->and(addrLocation(addrGet('/author/jane-doe/atom/')->assertStatus(301)))->toBe('/writers/jane-doe/feed/')
        ->and(feedDom('/writers/jane-doe/feed/')->evaluate('count(//item)'))->toBe(1.0);
});

it('serves a category with nothing in it as an empty feed, not a 404', function (): void {
    typesTerm($this->editor, 'category', 'Quiet');

    expect(feedDom('/category/quiet/feed/')->evaluate('count(//item)'))->toBe(0.0);
});

it('counts a feed request that carries s toward the search limit, as every public address does', function (): void {
    foreach (range(1, 30) as $request) {
        addrGet('/feed/?s=harbour')->assertOk();
    }

    addrGet('/feed/?s=harbour')->assertStatus(429);
});

it('tells search engines to keep a feed out of their results, and to keep away while the site discourages them (I-291)', function (): void {
    expect(addrGet('/feed/')->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');

    $this->app['env'] = 'production';

    expect(addrGet('/feed/')->headers->get('X-Robots-Tag'))->toBe('noindex, follow');

    cmSettings(['discourage_search_engines' => true], 'seo');

    expect(addrGet('/feed/')->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
});
