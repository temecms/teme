<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
});

it('shows every tab on Posts, at zero too, in the order All, Mine, Published, Private, Drafts, In review, Scheduled, Trash', function (): void {
    lifecycleDraft($this->editor, 'Market day');

    $this->actingAs($this->editor)
        ->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tabs', [
                ['key' => 'all', 'label' => 'All', 'count' => 1],
                ['key' => 'mine', 'label' => 'Mine', 'count' => 1],
                ['key' => 'published', 'label' => 'Published', 'count' => 0],
                ['key' => 'private', 'label' => 'Private', 'count' => 0],
                ['key' => 'drafts', 'label' => 'Drafts', 'count' => 1],
                ['key' => 'review', 'label' => 'In review', 'count' => 0],
                ['key' => 'scheduled', 'label' => 'Scheduled', 'count' => 0],
                ['key' => 'trash', 'label' => 'Trash', 'count' => 0],
            ]));
});

it('gives Pages the same tabs as Posts, the one list every type shares (I-1316)', function (): void {
    $this->actingAs($this->editor)
        ->get(route('content.index', ['type' => 'pages']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tabs', fn ($tabs): bool => collect($tabs)->pluck('key')->all() === ['all', 'mine', 'published', 'private', 'drafts', 'review', 'scheduled', 'trash']));
});

it('answers a tab at zero with an empty list that no filter narrowed, so it says "nothing here yet"', function (): void {
    lifecycleDraft($this->editor, 'Market day');

    $this->actingAs($this->editor)
        ->get(route('content.index', ['type' => 'posts', 'tab' => 'scheduled']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 0)
            ->where('filtered', false)
            ->where('filters.tab', 'scheduled'));
});

it('sends no tree for a type without a nesting taxonomy', function (): void {
    taxonomyTerm('category', 'Transport');

    $this->actingAs($this->editor)
        ->get(route('content.index', ['type' => 'pages']))
        ->assertInertia(fn (Assert $page) => $page->where('termTrees', []));
});

it('keeps the chosen category in the filters, so the select shows it, and lists its children\'s items too', function (): void {
    $transport = taxonomyTerm('category', 'Transport');
    $roads = taxonomyTerm('category', 'Roads', $transport->id);
    typesDraft($this->editor, 'post', 'Harbour news', [$transport->id]);
    $this->travel(1)->minutes();
    typesDraft($this->editor, 'post', 'Market day', [$roads->id]);
    $this->travel(1)->minutes();
    typesDraft($this->editor, 'post', 'Ferry fares');

    $this->actingAs($this->editor)
        ->get(route('content.index', ['type' => 'posts', 'category' => $transport->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.category', $transport->id)
            ->where('filtered', true)
            ->where('rows', fn ($rows): bool => collect($rows)->pluck('title')->sort()->values()->all() === ['Harbour news', 'Market day']));
});

it('draws a filter for each nesting taxonomy, its terms in tree order, and shows each row\'s terms in its column', function (): void {
    $list = uiComponent('pages/content/index.tsx');

    // A source scan, written loosely where the formatter may break a line.
    expect($list)->toMatch('/\.filter\(\s*\(taxonomy\) => taxonomy\.hierarchical,?\s*\)/')
        ->toContain("__('All :plural'")
        ->toContain('label={taxonomy.singular}')
        ->toContain('inTreeOrder(')
        ->toMatch('/<ChoiceFilter\s/')
        ->toContain('terms={row.terms[taxonomy.key] ?? []}')
        ->toContain('<TermSearchFilter')
        ->and(uiComponent('components/ui/select-field.tsx'))->toContain('option.depth')
        ->and(uiComponent('lib/term-tree.ts'))->toContain('export function inTreeOrder(')
        ->and(uiComponent('components/list/bulk-edit-dialog.tsx'))->toContain('inTreeOrder(')
        ->not->toContain('function ordered(')
        ->not->toContain("only: ['termTrees']");
});
