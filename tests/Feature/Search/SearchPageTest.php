<?php

declare(strict_types=1);

use App\Domain\Site\ListingViews;
use App\Domain\Site\SearchViews;
use App\Domain\Site\ViewModels\ListingView;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    $this->site = dtSite($this->author);
});

it('answers /?s= with results, snippets, and noindex', function (): void {
    // In production, where a page's own robots header is the only one (I-291).
    $this->app['env'] = 'production';

    $response = addrGet('/?s=lifeboat');

    $response->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow');
    expect($response->getContent())->toContain('<mark>lifeboat</mark>')
        ->toContain('<meta name="robots" content="noindex, follow">')
        ->toContain('value="lifeboat"');
});

it('pages at /page/N/?s=', function (): void {
    foreach (range(1, 14) as $n) {
        searchPublished($this->author, "Crew {$n}", 'heron watch');
    }

    $dom = dtDom(addrGet('/?s=heron')->getContent());

    // Page 2's number and "Next" both lead there; no page link may drop the query.
    expect($dom->query('//nav[@aria-label="Pages"]//a[contains(@href,"/page/2/?s=heron")]')->length)->toBeGreaterThanOrEqual(1)
        ->and($dom->query('//nav[@aria-label="Pages"]//a[not(contains(@href,"?s=heron"))]')->length)->toBe(0);
    addrGet('/page/2/?s=heron')->assertOk();
    addrGet('/page/3/?s=heron')->assertNotFound();
});

it('shows the form without searching when the query is empty', function (): void {
    expect(addrGet('/?s=')->assertOk()->getContent())->toContain('Type a word or two to search the site.');
});

it('says nothing matched, with a 200', function (): void {
    expect(addrGet('/?s=zeppelin')->assertOk()->getContent())->toContain('Nothing matched');
});

it('escapes the query everywhere it is shown', function (): void {
    expect(addrGet('/?s=%3Cscript%3Ealert(1)%3C/script%3E')->getContent())->not->toContain('<script>alert(1)');
});

it('limits searches to thirty a minute per reader', function (): void {
    foreach (range(1, 30) as $n) {
        addrGet('/?s=lifeboat')->assertOk();
    }

    addrGet('/?s=lifeboat')->assertStatus(429)->assertHeader('Retry-After')->assertSee('Too many searches');
    addrGet('/')->assertOk();
});

it('leaves the front page alone when there is no s', function (): void {
    // In production, where a page's own robots header is the only one (I-291).
    $this->app['env'] = 'production';

    addrGet('/')->assertOk()->assertHeaderMissing('X-Robots-Tag');
});

it('answers a search through core\'s fallback view when the theme is broken', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/broken')]]);
    themeActivate('broken');

    addrGet('/?s=lifeboat')->assertOk()
        ->assertSee('data-fallback="search"', false)
        ->assertSee('<mark>lifeboat</mark>', false);
});

it('gives a search the listing view every archive receives, with a search subject', function (): void {
    $view = app(SearchViews::class)->for('lifeboat', 1);

    expect($view)->toBeInstanceOf(ListingView::class)
        ->and($view->subject?->kind)->toBe('search')
        ->and($view->search?->query)->toBe('lifeboat')
        ->and(array_map(fn ($result) => $result->item, $view->search?->results ?? []))->toEqual($view->items)
        ->and($view->items)->not->toBeEmpty();
});

it('draws a search with the theme\'s archive when the theme has no search template', function (): void {
    // The good fixture has archive and no search: study B §3d's fallback, fed the one listing view.
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');

    addrGet('/?s=lifeboat')->assertOk()->assertHeader('X-Teme-Template', 'theme::templates.archive');
});

it('loads listing items for the given IDs, in the order given', function (): void {
    $first = searchPublished($this->author, 'First', 'one');
    $second = searchPublished($this->author, 'Second', 'two');

    // 1.6c's, so this passes from the start: the search page relies on its order.
    $items = app(ListingViews::class)->items([$second->id, $first->id]);

    expect(array_map(fn ($item): string => $item->title, $items))->toBe(['Second', 'First']);
});
