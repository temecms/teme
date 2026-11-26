<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardUsage;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\SiteAddresses;
use App\Domain\Site\ViewModels\SiteView;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
});

it('reads a page\'s siblings, previous and next, and tags the page for them, only when a template asks', function (): void {
    $tree = PageTree::build();
    app(RenderRecord::class)->start(0);
    DB::enableQueryLog();

    $view = app(ArticleViews::class)->published($tree->team->refresh());
    $before = count(DB::getQueryLog());
    $tagsBefore = app(RenderRecord::class)->tags();

    expect($tagsBefore)->not->toContain(PageTags::childrenOf((string) $tree->about->id));

    expect($view->siblings)->toHaveCount(2)
        ->and($view->next?->title)->toBe('History')
        ->and($view->previous)->toBeNull();

    $afterFirstRead = count(DB::getQueryLog());
    $view->siblings;
    $view->next;

    expect($afterFirstRead)->toBeGreaterThan($before)
        ->and(count(DB::getQueryLog()))->toBe($afterFirstRead)
        ->and(app(RenderRecord::class)->tags())->toContain(PageTags::childrenOf((string) $tree->about->id));
});

it('draws a page\'s blocks and records their cards only when a template asks', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $article = pageArticleFromDocument($author, 'Sections', [
        docText('Opening words.'),
        ['type' => 'callout', 'attrs' => ['id' => '01J0000000000000000000000A', 'tone' => 'tip', 'text' => 'Mind the gap.']],
    ]);
    app(CardUsage::class)->start();

    // The body's own drawing records the callout; blocks drawn alone must too.
    $view = app(ArticleViews::class)->published($article->refresh());
    app(CardUsage::class)->start();

    expect(app(CardUsage::class)->names())->toBe([]);

    $view->blocks;

    expect(app(CardUsage::class)->names())->toBe(['callout']);
});

it('reads the posts page and the archives only when a template asks', function (): void {
    $site = new SiteView('Site', '', 'http://localhost/', 'en_GB', 'en-GB', 2026, app(SiteAddresses::class));
    DB::enableQueryLog();

    expect($site->name)->toBe('Site')
        ->and(DB::getQueryLog())->toBe([]);

    expect($site->postsUrl)->toBe('http://localhost/')
        ->and(DB::getQueryLog())->not->toBe([])
        ->and($site->archives)->toHaveKey('post');
});

it('gives no addresses when a view is built without them', function (): void {
    $site = new SiteView('Site', '', 'http://localhost/', 'en_GB', 'en-GB', 2026);

    expect($site->postsUrl)->toBe('')
        ->and($site->archives)->toBe([]);
});
