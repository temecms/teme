<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// uiComponent() lives in tests/Pest.php; lifecycleDraft(), listsPublish()
// and taxonomyTerm() are the earlier slices' helpers.

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    // An administrator, because only they may open every list: an editor
    // gets 403 on Users.
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('gives every list screen exactly one h1', function (string $name, array $parameters, string $component): void {
    $this->actingAs($this->admin)
        ->get(route($name, $parameters))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));

    // A source scan: PageHeader draws the only h1, itself or through the
    // ListPage pattern that draws it once, and the page draws none of its own.
    $source = uiComponent("pages/{$component}.tsx");

    expect(substr_count($source, '<PageHeader') + substr_count($source, '<ListPage'))->toBe(1)
        ->and($source)->not->toContain('<h1')
        ->and(substr_count(uiComponent('components/patterns/list-page.tsx'), '<PageHeader'))->toBe(1)
        ->and(uiComponent('components/patterns/page-header.tsx'))->toContain("as: Title = 'h1'")
        ->and(substr_count(uiComponent('components/list/page-header.tsx'), '<h1'))->toBe(1);
})->with([
    'posts' => ['content.index', ['type' => 'posts'], 'content/index'],
    'pages' => ['content.index', ['type' => 'pages'], 'content/index'],
    'categories' => ['terms.index', ['taxonomy' => 'categories'], 'terms/index'],
    'tags' => ['terms.index', ['taxonomy' => 'tags'], 'terms/index'],
    'users' => ['users.index', [], 'users/index'],
]);

it('shows each row\'s status on a pill in its Status column, no longer after its title', function (): void {
    lifecycleDraft($this->admin, 'Drafted');
    $this->travel(1)->minutes();
    listsPublish(lifecycleDraft($this->admin, 'Published'), $this->admin);

    $this->actingAs($this->admin)
        ->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.title', 'Published')
            ->where('rows.0.status', 'published')
            ->where('rows.1.title', 'Drafted')
            ->where('rows.1.status', 'draft'));

    // A source scan: the state is a pill in its own column (docs/proposals/content-lists.md).
    expect(uiComponent('pages/content/index.tsx'))->toMatch("/id:\s*'status'/")
        ->toContain('<StatusCell')
        ->not->toContain('statusSuffix(row)');
});

it('offers a way back on every pager', function (string $name, array $parameters, string $component): void {
    // The Users list has no pager — a site has few people — so there is
    // nothing to add there.
    foreach (range(1, 55) as $n) {
        if ($name === 'content.index') {
            lifecycleDraft($this->admin, "Post {$n}");
        } else {
            taxonomyTerm('category', sprintf('Category %02d', $n));
        }
    }

    $props = fn (array $query = []): array => $this->actingAs($this->admin)
        ->get(route($name, [...$parameters, ...$query]))
        ->viewData('page')['props'];

    $first = $props();
    $second = $props(['page' => 2]);
    $back = $props(['page' => $second['paging']['number'] - 1]);

    expect($first['paging'])->toMatchArray(['number' => 1, 'last' => 2])
        ->and($second['rows'])->toHaveCount(5)
        ->and($second['paging'])->toMatchArray(['number' => 2, 'last' => 2])
        ->and(array_column($back['rows'], 'id'))->toBe(array_column($first['rows'], 'id'))
        ->and($back['paging'])->toBe($first['paging']);

    // A source scan: the page draws the shared pager.
    expect(uiComponent("pages/{$component}.tsx"))->toMatch('/<(?:NumberedPager|Pager)\b/');
})->with([
    'posts' => ['content.index', ['type' => 'posts'], 'content/index'],
    'categories' => ['terms.index', ['taxonomy' => 'categories'], 'terms/index'],
]);

it('shows the nothing-yet empty state when a list has no rows at all', function (): void {
    $this->actingAs($this->admin)
        ->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 0)->where('filtered', false));

    $this->actingAs($this->admin)
        ->get(route('terms.index', ['taxonomy' => 'categories']))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 0)->where('search', ''));

    // A source scan: both pages draw the nothing-yet kind.
    foreach (['pages/content/index.tsx', 'pages/terms/index.tsx'] as $page) {
        expect(uiComponent($page))->toContain('<EmptyState')->toContain("'nothing-yet'");
    }
});

it('shows the nothing-matches empty state, with a way to clear the filters, when a search excludes everything', function (): void {
    lifecycleDraft($this->admin, 'Hello');
    taxonomyTerm('category', 'News');

    $this->actingAs($this->admin)
        ->get(route('content.index', ['type' => 'posts', 'search' => 'zzz']))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 0)->where('filtered', true));

    $this->actingAs($this->admin)
        ->get(route('terms.index', ['taxonomy' => 'categories', 'search' => 'zzz']))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 0)->where('search', 'zzz'));

    // Clearing the filters is visiting the list without them, and the rows
    // come back.
    $this->actingAs($this->admin)
        ->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1));

    $this->actingAs($this->admin)
        ->get(route('terms.index', ['taxonomy' => 'categories']))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1));

    // A source scan: both pages pass a clear link, and the component draws
    // it as a control, not as text telling people what to do.
    foreach (['pages/content/index.tsx', 'pages/terms/index.tsx'] as $page) {
        expect(uiComponent($page))->toContain("'nothing-matches'")->toContain('clearHref=');
    }

    expect(uiComponent('components/list/empty-state.tsx'))->toContain('clearHref')->toContain('<Link');
});
