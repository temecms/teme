<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\Site\ViewModels\ItemLink;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
});

/**
 * Links as `[title, address, type]`.
 *
 * @param  list<ItemLink>  $links
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function viewLinks(array $links): array
{
    return array_map(fn (ItemLink $link): array => [$link->title, $link->url, $link->type], $links);
}

function viewOf(Content $page): ArticleView
{
    return app(ArticleViews::class)->published($page->refresh());
}

/**
 * Gives each of these pages, written straight to the table, a published
 * revision of its own titled as the row, copied from the parent's.
 *
 * @param  list<string>  $ids
 */
function viewRevisions(Content $parent, array $ids): void
{
    $template = (array) DB::table('content_revisions')->where('id', $parent->refresh()->published_revision_id)->first();

    foreach ($ids as $id) {
        $revision = strtolower((string) Str::ulid());
        DB::table('content_revisions')->insert(['id' => $revision, 'content_id' => $id, 'title' => (string) DB::table('contents')->where('id', $id)->value('title')] + $template);
        DB::table('contents')->where('id', $id)->update(['published_revision_id' => $revision, 'working_revision_id' => $revision]);
    }
}

it('gives a page its parent, its visible ancestors and its published children', function (): void {
    $team = viewOf($this->tree->team);
    $about = viewOf($this->tree->about);

    expect(viewLinks([$team->parent]))->toBe([['About', '/about/', 'page']])
        ->and(viewLinks($team->ancestors))->toBe([['About', '/about/', 'page']])
        ->and(viewLinks($team->children))->toBe([['People', '/about/team/people/', 'page']])
        ->and(viewLinks(viewOf($this->tree->people)->ancestors))->toBe([['About', '/about/', 'page'], ['Team', '/about/team/', 'page']])
        ->and($about->parent)->toBeNull()
        ->and($about->ancestors)->toBe([])
        ->and(viewLinks($about->children))->toBe([['Team', '/about/team/', 'page'], ['History', '/about/history/', 'page']]);
});

it('gives a page under a hidden parent no parent, and its ancestors without it', function (): void {
    $secretChild = viewOf($this->tree->secretChild);
    $web = viewOf($this->tree->web);

    expect($secretChild->parent)->toBeNull()
        ->and(viewLinks($secretChild->ancestors))->toBe([['About', '/about/', 'page']])
        ->and($web->parent)->toBeNull()
        ->and($web->ancestors)->toBe([])
        ->and(viewOf($this->tree->archivedChild)->parent)->toBeNull();
});

it('gives children in their order, published and live only, at most 100', function (): void {
    $hub = addrPublished($this->tree->editor, 'Hub', 'page');
    $ids = PageTree::many($hub, 103, withAddresses: true);
    viewRevisions($hub, $ids);
    DB::table('contents')->where('id', $ids[0])->update(['status' => 'draft']);
    DB::table('contents')->where('id', $ids[1])->update(['published_at' => now()->addDay()]);

    $children = viewOf($hub)->children;

    expect($children)->toHaveCount(100)
        ->and($children[0]->title)->toBe('Many 3')
        ->and($children[0]->url)->toBe('/hub/many-3/')
        ->and($children[99]->title)->toBe('Many 102');
});

it('gives a type that doesn\'t nest no parent, no ancestors and no children, reading nothing for them', function (): void {
    $post = $this->tree->teamPost->refresh();
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $view = app(ArticleViews::class)->published($post);

    expect($view->parent)->toBeNull()
        ->and($view->ancestors)->toBe([])
        ->and($view->children)->toBe([])
        ->and(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'parent_id')))->toBe([]);
});

it('draws a preview with the live parent only', function (): void {
    $plans = $this->tree->plans->refresh();
    $web = $this->tree->web->refresh();

    expect(viewLinks([app(ArticleViews::class)->ofRevision($plans->id, (string) $plans->working_revision_id)->parent]))->toBe([['About', '/about/', 'page']])
        ->and(app(ArticleViews::class)->ofRevision($web->id, (string) $web->working_revision_id)->parent)->toBeNull();
});
