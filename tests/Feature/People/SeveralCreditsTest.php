<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\People\Credit;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\CommentViews;
use App\Domain\Site\ListingViews;
use App\Domain\Site\ViewModels\Person;
use App\Domain\Sitemaps\SitemapAddress;
use App\Domain\Sitemaps\Sitemaps;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\Languages\LanguageSite;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('editor');
    $this->grace = User::factory()->create(['name' => 'Grace Hopper'])->assignRole('editor');
    app(PathProjection::class)->syncUser($this->ada);
    app(PathProjection::class)->syncUser($this->grace);
    $this->reuters = Credited::guest('Reuters', 'reuters', 'News agency');
    $this->article = addrPublished($this->ada, 'Harbour news');
    Credited::set($this->article->id, [Credit::person($this->reuters), Credit::account((int) $this->ada->id)]);
    $this->adaPage = '/author/'.$this->ada->slug.'/';
});

it('shows both, the guest first, in the view data and on a card', function (): void {
    $both = [new Person('Reuters', '/author/reuters/', 'News agency'), new Person('Ada Lovelace', $this->adaPage, null)];

    expect(app(ArticleViews::class)->published($this->article)->authors)->toEqual($both)
        ->and(app(ListingViews::class)->items([(string) $this->article->id])[0]->authors)->toEqual($both);
});

it('lists the article on both author pages, and only there', function (): void {
    addrGet('/author/reuters/')->assertOk()->assertSee('Harbour news');
    addrGet($this->adaPage)->assertOk()->assertSee('Harbour news');
    addrGet('/author/'.$this->grace->slug.'/')->assertOk()->assertDontSee('Harbour news');
});

it('answers a guest\'s author page with their items and their bio', function (): void {
    $view = app(ListingViews::class)->forAuthor((object) ['kind' => 'person', 'id' => $this->reuters, 'name' => 'Reuters', 'slug' => 'reuters', 'bio' => 'News agency', 'path' => 'author/reuters'], 1);

    expect(array_map(fn ($item): string => $item->title, $view->items))->toBe(['Harbour news'])
        ->and($view->pagination->total)->toBe(1)
        ->and($view->subject->description)->toBe('News agency');

    addrGet('/author/reuters/')->assertOk()->assertSee('Reuters')->assertSee('News agency');
});

it('marks Ada\'s comment as the author\'s, though she isn\'t first', function (): void {
    cmComment($this->article, attributes: ['author_name' => 'Ada Lovelace', 'author_user_id' => $this->ada->id]);
    $items = app(CommentViews::class)->forItem($this->article)?->items ?? [];

    expect($items[0]->byAuthor)->toBeTrue();
});

it('writes one dc:creator each, in order', function (): void {
    $feed = feedParse((string) addrGet('/feed/')->assertOk()->getContent());

    expect(array_map(fn (DOMNode $node): string => $node->textContent, iterator_to_array($feed->query('//item/dc:creator') ?: [])))->toBe(['Reuters', 'Ada Lovelace'])
        ->and(feedParse((string) addrGet('/author/reuters/feed/')->assertOk()->getContent())->evaluate('string(//item/title)'))->toBe('Harbour news')
        ->and(feedParse((string) addrGet($this->adaPage.'feed/')->assertOk()->getContent())->evaluate('string(//item/title)'))->toBe('Harbour news');
});

it('names two people in the JSON-LD, each with their page', function (): void {
    $people = array_values(array_filter(sdGraph('/harbour-news/'), fn (array $node): bool => ($node['@type'] ?? null) === 'Person'));

    expect(array_map(fn (array $node): array => [$node['name'], $node['url']], $people))
        ->toBe([['Reuters', 'http://localhost/author/reuters/'], ['Ada Lovelace', 'http://localhost'.$this->adaPage]]);
});

it('gives the first as the API\'s author, both as authors, and lists it for either slug', function (): void {
    Switches::apiOn();
    $item = $this->getJson('/api/v1/items/'.$this->article->id)->assertOk()->json('data');
    $listed = fn (string $slug): array => collect($this->getJson('/api/v1/items?author='.$slug)->assertOk()->json('data'))->pluck('id')->all();

    expect($item['author']['name'])->toBe('Reuters')
        ->and(array_column($item['authors'], 'name'))->toBe(['Reuters', 'Ada Lovelace'])
        ->and($item['authors'][0])->toBe($item['author'])
        ->and($item['authors'][0]['path'])->toBe('/author/reuters/')
        ->and($listed('reuters'))->toBe([(string) $this->article->id])
        ->and($listed($this->ada->slug))->toBe([(string) $this->article->id])
        ->and($listed($this->grace->slug))->toBe([])
        ->and($listed('nobody-at-all'))->toBe([]);
});

it('joins both names on the .md page', function (): void {
    Switches::llmsOn();
    expect((string) addrGet('/harbour-news.md')->assertOk()->getContent())->toContain(' · Reuters, Ada Lovelace · ');
});

it('lists the guest in the authors sitemap, and the owner only while something credits them', function (): void {
    $wire = addrPublished($this->grace, 'Wire story');
    Credited::set($wire->id, [Credit::person($this->reuters)]);

    expect(array_map(fn (SitemapAddress $address): string => $address->loc, app(Sitemaps::class)->page('authors')?->addresses ?? []))->toBe(['http://localhost'.$this->adaPage, 'http://localhost/author/reuters/']);
});

it('answers Ada\'s page from her account\'s row, which the update left alone', function (): void {
    expect(DB::table('paths')->where('path', ltrim(rtrim($this->adaPage, '/'), '/'))->first(['target_type', 'target_id']))->toEqual((object) ['target_type' => 'user', 'target_id' => (string) $this->ada->id]);

    addrGet($this->adaPage)->assertOk()->assertSee('Harbour news');
});

it('answers 404 for an address row of a kind this release doesn\'t know', function (): void {
    DB::table('paths')->insert(['path' => 'author/other', 'target_type' => 'other', 'target_id' => $this->reuters, 'is_current' => true]);

    addrGet('/author/other/')->assertNotFound();
    addrGet('/author/other/feed/')->assertNotFound();
});

it('redirects a guest\'s old address, and its feed\'s, to the current one', function (): void {
    DB::table('paths')->where('target_type', 'person')->where('target_id', $this->reuters)->update(['is_current' => false, 'replaced_at' => now()]);
    DB::table('paths')->insert(['path' => 'author/reuters-news', 'target_type' => 'person', 'target_id' => $this->reuters, 'is_current' => true]);

    expect(addrLocation(addrGet('/author/reuters/')->assertStatus(301)))->toBe('/author/reuters-news/')
        ->and(addrLocation(addrGet('/author/reuters/feed/')->assertStatus(301)))->toBe('/author/reuters-news/feed/');
});

it('answers the French author page with the French items that credit the person', function (): void {
    $site = LanguageSite::build();
    app(PathProjection::class)->syncUser($site->editor);
    Credited::set($site->postFr, [Credit::person($this->reuters), Credit::account((int) $site->editor->id)]);
    $editor = '/'.addrCurrent('user', (string) $site->editor->id).'/';

    addrGet('/fr/author/reuters/')->assertOk()
        ->assertHeader('Content-Language', 'fr')
        ->assertSee('Bonjour')
        ->assertDontSee('Hello');

    addrGet('/fr'.$editor)->assertOk()
        ->assertHeader('Content-Language', 'fr')
        ->assertSee('Bonjour')
        ->assertDontSee('Hello');
});
