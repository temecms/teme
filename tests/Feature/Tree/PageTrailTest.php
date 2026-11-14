<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\ViewModels\Crumb;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->tree = PageTree::build();
});

/**
 * The page's trail as `[name, address]` pairs.
 *
 * @return list<array{0: string, 1: string}>
 */
function trailOf(Content $page): array
{
    return array_map(fn (Crumb $crumb): array => [$crumb->name, $crumb->url], app(ArticleViews::class)->published($page->refresh())->breadcrumbs ?? []);
}

it('gives a page\'s trail as home, its visible ancestors, the page', function (): void {
    sdTrailTheme();

    expect(trailOf($this->tree->people))->toBe([['Home', '/'], ['About', '/about/'], ['Team', '/about/team/'], ['People', '/about/team/people/']])
        ->and(headValue(headDom('/about/team/people/'), '//nav[@aria-label="Breadcrumb"]'))->toBe('HomeAboutTeamPeople');
});

it('leaves a hidden ancestor out of the trail', function (): void {
    sdTrailTheme();

    expect(trailOf($this->tree->secretChild))->toBe([['Home', '/'], ['About', '/about/'], ['Secret child', '/about/secret-child/']])
        ->and(trailOf($this->tree->archivedChild))->toBe([['Home', '/'], ['About', '/about/'], ['Archived child', '/about/archived-child/']])
        ->and(trailOf($this->tree->web))->toBe([['Home', '/'], ['Web', '/web/']]);
});

it('names an ancestor by its published title, never its draft\'s', function (): void {
    sdTrailTheme();
    $about = $this->tree->about->refresh();
    $working = ContentRevision::query()->findOrFail($about->working_revision_id);
    app(SaveDraft::class)->execute(new SaveDraftInput($about->id, 'SECRET TITLE', (array) $working->document, (array) $working->fields, $about->working_revision_id, (string) $this->tree->editor->id, (array) $working->terms));

    expect(trailOf($this->tree->team))->toBe([['Home', '/'], ['About', '/about/'], ['Team', '/about/team/']]);
});

it('leaves the home page out when it is an ancestor', function (): void {
    sdTrailTheme();
    addrChange($this->tree->admin, ['patterns' => ['page' => '/{parent}/{slug}/'], 'frontShows' => 'page', 'frontPageId' => $this->tree->team->id]);

    expect(array_column(trailOf($this->tree->people), 0))->toBe(['Home', 'About', 'People']);
});

it('writes a BreadcrumbList of the same steps', function (): void {
    sdTrailTheme();
    $page = 'http://localhost/about/team/people/';

    expect(sdNode(sdGraph('/about/team/people/'), $page.'#breadcrumb')['itemListElement'] ?? null)->toEqual([
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'http://localhost/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'About', 'item' => 'http://localhost/about/'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Team', 'item' => 'http://localhost/about/team/'],
        ['@type' => 'ListItem', 'position' => 4, 'name' => 'People'],
    ]);
});

it('builds no trail on the default theme', function (): void {
    config(['teme.theme_paths' => []]);
    themeActivate('default');

    expect(app(ArticleViews::class)->published($this->tree->people->refresh())->breadcrumbs)->toBeNull()
        ->and(sdNode(sdGraph('/about/team/people/'), 'http://localhost/about/team/people/#breadcrumb'))->toBeNull();
});
