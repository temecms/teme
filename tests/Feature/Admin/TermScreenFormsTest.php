<?php

declare(strict_types=1);

use App\Domain\Taxonomy\Models\Term;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
});

it('adds a category with its slug, parent and description from the full form, and names it in the toast', function (): void {
    $transport = taxonomyTerm('category', 'Transport');

    $this->actingAs($this->editor)->from('/admin/categories')
        ->post(route('terms.store', ['taxonomy' => 'categories']), ['name' => 'Ferry routes', 'slug' => 'crossings', 'parent_id' => $transport->id, 'description' => 'Every crossing, by season.'])
        ->assertRedirect(route('terms.index', ['taxonomy' => 'categories']))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Added “Ferry routes”.']);

    expect(Term::query()->where('name', 'Ferry routes')->sole()->only(['slug', 'parent_id', 'description']))
        ->toBe(['slug' => 'crossings', 'parent_id' => $transport->id, 'description' => 'Every crossing, by season.']);
});

it('keeps a subcategory\'s parent and description when its edit form sends them back with a new name', function (): void {
    $transport = taxonomyTerm('category', 'Transport');
    $roads = taxonomyTerm('category', 'Roads', $transport->id);
    Term::query()->whereKey($roads->id)->update(['description' => 'Streets and highways.']);

    // What the edit sheet sends: every field, filled in from the row.
    $row = collect($this->actingAs($this->editor)->get(route('terms.index', ['taxonomy' => 'categories']))->viewData('page')['props']['rows'])->firstWhere('name', 'Roads');

    $this->actingAs($this->editor)->from('/admin/categories')
        ->put(route('terms.update', ['taxonomy' => 'categories', 'term' => $roads->id]), ['name' => 'Roads and streets', 'slug' => $row['slug'], 'parent_id' => $row['parentId'], 'description' => $row['description']])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Saved.']);

    expect(Term::query()->find($roads->id)->only(['name', 'parent_id', 'description']))
        ->toBe(['name' => 'Roads and streets', 'parent_id' => $transport->id, 'description' => 'Streets and highways.']);
});

it('names the screen after its taxonomy, with no taxonomy tabs or Set up, and the full add form beside the rows', function (): void {
    expect(uiComponent('pages/terms/index.tsx'))
        ->not->toContain('<InPageNav')
        ->not->toContain('data-test="term-setup"')
        ->toContain('const heading = declared.plural;')
        ->toContain('title={heading}')
        ->toContain('<Head title={declared.plural} />')
        ->toContain('lg:flex-row')
        ->toContain('lg:w-75')
        ->toContain('data-test="term-add"')
        ->toMatch("/resetOnSuccess=\{\[\s*'name',\s*'slug',\s*'description',?\s*\]\}/")
        ->toContain("document.getElementById('term-add-name')?.focus()")
        ->toContain("__('New :singular'")
        ->toContain("__('Add :singular'")
        ->toContain('Use the form to add the first one.')
        ->not->toContain('usePage()');
});

it('gives Add and Edit the same fields, filled in from the row, a parent only where terms nest, and never the term\'s own branch as its parent', function (): void {
    $screen = uiComponent('pages/terms/index.tsx');

    expect(substr_count($screen, '<TermFields'))->toBe(2)
        ->and($screen)->toContain('name="parent_id"')
        ->toContain("defaultValue={term?.parentId ?? ''}")
        ->toContain('name="description"')
        ->toContain("defaultValue={term?.description ?? ''}")
        ->toContain("defaultValue={term?.slug ?? ''}")
        ->toContain('{declared.hierarchical && (')
        ->toContain("emptyLabel={__('None')}")
        ->toContain('The last part of its address. Leave it empty to make one from the name.')
        ->toContain('row.depth <= depth')
        ->toContain('Later (I-1490)')
        ->not->toContain('function TermEditor(');
});

it('indents each row under its parent, says so to screen readers, and links each count to the list it counts', function (): void {
    expect(uiComponent('pages/terms/index.tsx'))
        ->toContain("'— '.repeat(row.depth)")
        ->toContain('aria-hidden="true"')
        ->toContain('data-test="term-name"')
        ->toContain("__('under :parent'")
        ->toContain('data-test="term-count"')
        ->toMatch('/\[declared\.key\]:\s*row\.id/')
        ->toContain('min-h-6')
        ->toContain('min-w-6')
        ->toContain("__(':items in :name'")
        ->toContain('count === 0');
});

it('says what deleting a term does before it is done: who loses it, where its children go, and that it can\'t be undone (I-225)', function (): void {
    expect(uiComponent('pages/terms/index.tsx'))
        ->toContain('function deleteWarning(')
        ->toContain("__(':items loses this :singular.'")
        ->toContain("__(':items lose this :singular.'")
        ->toContain("__('to the top')")
        ->toContain('moves up a level, :where. Nothing under it is deleted.')
        ->toContain('move up a level, :where. Nothing under them is deleted.')
        ->toContain("'This can’t be undone.'")
        ->toContain("title={__('Delete “:name”?'")
        ->toContain("confirmLabel={__('Delete :singular'");
});
