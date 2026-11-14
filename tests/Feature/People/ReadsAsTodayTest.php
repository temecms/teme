<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
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

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('editor');
    $this->ada->forceFill(['bio' => 'Writes about engines.'])->save();
    $this->grace = User::factory()->create(['name' => 'Grace Hopper'])->assignRole('editor');
    app(PathProjection::class)->syncUser($this->ada);
    app(PathProjection::class)->syncUser($this->grace);
    $this->article = addrPublished($this->ada, 'Harbour news');
    $this->page = addrPublished($this->grace, 'About us', 'page');
    $this->adaPage = '/author/'.$this->ada->slug.'/';
});

it('shows the owner as the article\'s author, as before', function (): void {
    expect(app(ArticleViews::class)->published($this->article)->authors)->toEqual([new Person('Ada Lovelace', $this->adaPage, 'Writes about engines.')])
        ->and(DB::table('people')->count())->toBe(0);
});

it('lists the article on its owner\'s author page, counted', function (): void {
    $view = app(ListingViews::class)->forAuthor($this->ada, 1);

    expect(array_map(fn ($item): string => $item->title, $view->items))->toBe(['Harbour news'])
        ->and($view->items[0]->authors)->toEqual([new Person('Ada Lovelace', $this->adaPage, 'Writes about engines.')])
        ->and($view->pagination->total)->toBe(1)
        ->and($view->subject->title)->toBe('Ada Lovelace')
        ->and($view->subject->description)->toBe('Writes about engines.');
});

it('marks the owner\'s comment as the author\'s', function (): void {
    cmComment($this->article, attributes: ['author_name' => 'Ada Lovelace', 'author_user_id' => $this->ada->id]);
    cmComment($this->article, attributes: ['author_name' => 'Grace Hopper', 'author_user_id' => $this->grace->id]);
    $marks = collect(app(CommentViews::class)->forItem($this->article)?->items ?? [])->mapWithKeys(fn ($view): array => [$view->name => $view->byAuthor])->all();

    expect($marks)->toEqual(['Ada Lovelace' => true, 'Grace Hopper' => false]);
});

it('names the owner in both feeds', function (): void {
    $feed = feedParse((string) addrGet('/feed/')->assertOk()->getContent());
    $own = feedParse((string) addrGet($this->adaPage.'feed/')->assertOk()->getContent());

    expect($feed->evaluate('count(//item/dc:creator)'))->toBe(1.0)
        ->and($feed->evaluate('string(//item/dc:creator)'))->toBe('Ada Lovelace')
        ->and($own->evaluate('string(//item/title)'))->toBe('Harbour news');
});

it('lists the owner in the authors sitemap', function (): void {
    expect(array_map(fn (SitemapAddress $address): string => $address->loc, app(Sitemaps::class)->page('authors')?->addresses ?? []))->toBe(['http://localhost'.$this->adaPage]);
});

it('names the owner in the JSON-LD', function (): void {
    $people = array_values(array_filter(sdGraph('/harbour-news/'), fn (array $node): bool => ($node['@type'] ?? null) === 'Person'));

    expect(array_map(fn (array $node): array => [$node['name'], $node['url']], $people))->toBe([['Ada Lovelace', 'http://localhost'.$this->adaPage]]);
});

it('gives the owner as the API\'s author and the only one of its authors', function (): void {
    Switches::apiOn();
    $item = $this->getJson('/api/v1/items/'.$this->article->id)->assertOk()->json('data');
    $listed = collect($this->getJson('/api/v1/items?author='.$this->ada->slug)->assertOk()->json('data'))->pluck('id')->all();

    expect($item['author']['name'])->toBe('Ada Lovelace')
        ->and($item['author']['path'])->toBe($this->adaPage)
        ->and($item['authors'])->toBe([$item['author']])
        ->and($listed)->toBe([(string) $this->article->id]);
});

it('writes the owner\'s name on the .md page', function (): void {
    Switches::llmsOn();
    expect((string) addrGet('/harbour-news.md')->assertOk()->getContent())->toContain(' · Ada Lovelace · ');
});

it('answers the owner\'s author page', function (): void {
    addrGet($this->adaPage)->assertOk()->assertSee('Ada Lovelace')->assertSee('Harbour news');
});

it('lists nobody for an item with no owner', function (): void {
    DB::table('contents')->where('id', $this->article->id)->update(['author_id' => null]);
    Switches::apiOn();

    expect(app(ArticleViews::class)->published($this->article->refresh())->authors)->toBe([])
        ->and($this->getJson('/api/v1/items/'.$this->article->id)->assertOk()->json('data.author'))->toBeNull()
        ->and($this->getJson('/api/v1/items/'.$this->article->id)->assertOk()->json('data.authors'))->toBe([]);
});

it('reads an account\'s archive in index order, without a sort, while no bylines touch it', function (): void {
    planFillerPosts($this->ada, 2000);
    planFillerPosts(User::factory()->create(), 20000);
    // A bitmap scan can't give the order, so the plan left shows whether the index can.
    DB::statement('SET LOCAL enable_bitmapscan = off');

    $plan = planOf(fn () => app(ListingViews::class)->forAuthor($this->ada, 2), '"contents"."author_id" = ?');

    expect($plan)->toContain('using contents_author_published on contents')
        ->and($plan)->not->toContain('Sort');
});

it('keeps an index of each account\'s items by ID, so whether bylines touch them is read from indexes alone', function (): void {
    // A test's rows are never vacuumed, so its plans can't show an index-only scan; the benchmark's site does (Part 2.5a's records, Follow-up L2).
    $index = DB::selectOne("SELECT i.indexdef, x.indisvalid FROM pg_indexes i JOIN pg_class c ON c.relname = i.indexname JOIN pg_index x ON x.indexrelid = c.oid WHERE i.tablename = 'contents' AND i.indexname = 'contents_author_id_id'");

    expect($index?->indexdef)->toBe('CREATE INDEX contents_author_id_id ON public.contents USING btree (author_id, id)')
        ->and($index?->indisvalid)->toBeTrue();
});
