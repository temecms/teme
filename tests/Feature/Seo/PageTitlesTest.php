<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Head\PageHead;
use App\Domain\Themes\ViewIntent;
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
    $this->site = pageSiteName();
    $this->title = fn (string $uri, int $status = 200): string => headValue(headDom($uri, $status), '//title');
});

it('titles an item from its type\'s template, and uses its own SEO title word for word (I-290)', function (): void {
    headPost($this->author, 'Harbour news');
    headPost($this->author, 'Ferry fares', [BuiltInFields::SEO_TITLE => 'Ferry fares rise {site}']);

    expect(($this->title)('/harbour-news/'))->toBe("Harbour news – {$this->site}")
        ->and(($this->title)('/ferry-fares/'))->toBe('Ferry fares rise {site}');
});

it('fills an item\'s tokens: its primary category while it is one of its categories, else its first; its author, type, year and the separator setting (I-852)', function (): void {
    $news = headTerm('category', 'News');
    $local = headTerm('category', 'Local');
    cmSettings(['title_type_post' => '{title} {sep} {term} {sep} {author} {sep} {type} {sep} {year} {sep} {site}', 'title_separator' => '|'], SearchAndSocial::KEY);
    headPost($this->author, 'First category', [], [$news, $local]);
    headPost($this->author, 'Primary category', [BuiltInFields::PRIMARY_CATEGORY => $local->id], [$news, $local]);
    headPost($this->author, 'Primary no longer ticked', [BuiltInFields::PRIMARY_CATEGORY => $local->id], [$news]);
    $year = now()->format('Y');

    expect(($this->title)('/first-category/'))->toBe("First category | News | Jane Doe | Post | {$year} | {$this->site}")
        ->and(($this->title)('/primary-category/'))->toBe("Primary category | Local | Jane Doe | Post | {$year} | {$this->site}")
        ->and(($this->title)('/primary-no-longer-ticked/'))->toBe("Primary no longer ticked | News | Jane Doe | Post | {$year} | {$this->site}");
});

it('titles an archive by its own heading, with its page from page 2 as "Page 2 of 2", from its taxonomy\'s own row (I-853)', function (): void {
    $news = headTerm('category', 'News');
    $tides = headTerm('tag', 'Tides');
    cmSettings(['title_taxonomy_tag' => 'Tagged {title} {sep} {site}'], SearchAndSocial::KEY);

    foreach (range(1, 11) as $i) {
        headPost($this->author, "Story {$i}", [], [$news, $tides]);
    }

    expect(($this->title)('/category/news/'))->toBe("News – {$this->site}")
        ->and(($this->title)('/category/news/page/2/'))->toBe("News – Page 2 of 2 – {$this->site}")
        ->and(($this->title)('/tag/tides/'))->toBe("Tagged Tides – {$this->site}")
        ->and(($this->title)('/'.now()->format('Y').'/'))->toBe(now()->format('Y')." – {$this->site}");
});

it('titles the home page from its row, with its tagline, and a page shown there by its own SEO title when it has one (I-854)', function (): void {
    cmSettings(['tagline' => 'News of the city'], 'general');

    expect(($this->title)('/'))->toBe("{$this->site} – News of the city");

    $admin = User::factory()->create()->assignRole('administrator');
    $editor = User::factory()->create()->assignRole('editor');
    $welcome = lifecycleDraft($editor, 'Welcome', 'page');
    app(SaveDraft::class)->execute(new SaveDraftInput($welcome->id, 'Welcome', ['type' => 'doc', 'content' => []], [BuiltInFields::SEO_TITLE => 'The Ledger, Garden City’s paper'], $welcome->working_revision_id, (string) $editor->id));
    listsPublish($welcome, $editor);
    $news = addrPublished($editor, 'News', 'page');
    addrChange($admin, ['frontShows' => 'page', 'frontPageId' => $welcome->id, 'postsPageId' => $news->id]);

    expect(($this->title)('/'))->toBe('The Ledger, Garden City’s paper');
});

it('names the words searched in a search\'s title, as plain text, and a blank search as Search (I-851)', function (): void {
    headPost($this->author, 'Ferry fares');

    expect(($this->title)('/?s=ferry'))->toBe("Search results for “ferry” – {$this->site}")
        ->and(($this->title)('/?s='))->toBe("Search – {$this->site}");

    cmSettings(['title_search' => '{search} {sep} {site}'], SearchAndSocial::KEY);
    $html = (string) addrGet('/?s='.rawurlencode('<b>ferry</b> & {site}'))->assertOk()->getContent();

    expect(headValue(dtDom($html), '//title'))->toBe("<b>ferry</b> & {site} – {$this->site}")
        ->and($html)->toContain('<title>&lt;b&gt;ferry&lt;/b&gt; &amp; {site} – ');
});

it('titles the page for an address with nothing at it from its row', function (): void {
    expect(($this->title)('/no-such-page/', 404))->toBe("Page not found – {$this->site}");

    cmSettings(['title_not_found' => 'Lost at sea {sep} {site}'], SearchAndSocial::KEY);

    expect(($this->title)('/no-such-page/', 404))->toBe("Lost at sea – {$this->site}");
});

it('resolves an item\'s head values once for its view model, as the contract says (I-290)', function (): void {
    $item = headPost($this->author, 'Harbour news', [BuiltInFields::META_DESCRIPTION => 'What the harbour board decided.']);
    $view = app(ArticleViews::class)->published($item);
    $page = new DocumentPage(new ViewIntent('single', type: 'post'), $view);
    $heads = app(PageHead::class);

    $values = $heads->for($page);

    expect($values->title)->toBe("Harbour news – {$this->site}")
        ->and($values->description)->toBe('What the harbour board decided.')
        ->and($values->url)->toBe('http://localhost/harbour-news/')
        ->and($values->canonical)->toBe('http://localhost/harbour-news/')
        ->and($values->robots)->toBe(['max-snippet:-1', 'max-image-preview:large', 'max-video-preview:-1'])
        ->and($values->listed())->toBeTrue()
        ->and($values->robotsContent())->toBe('max-snippet:-1, max-image-preview:large, max-video-preview:-1')
        ->and($values->social())->toBeTrue()
        ->and($values->socialTitle)->toBe('Harbour news')
        ->and($values->socialDescription)->toBe('What the harbour board decided.')
        ->and($values->socialType)->toBe('article')
        ->and($values->image)->toBeNull()
        ->and([$values->prev, $values->next])->toBe([null, null])
        ->and(count(dashQueries(fn () => $heads->for($page))))->toBe(0)
        ->and($heads->for(new DocumentPage(new ViewIntent('single', type: 'post'), app(ArticleViews::class)->published($item))))->not->toBe($values);
});
