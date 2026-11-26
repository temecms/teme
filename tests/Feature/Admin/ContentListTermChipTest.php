<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
});

it('names the tag a list is filtered by, which no control on it shows (B-071)', function (): void {
    $harbour = taxonomyTerm('tag', 'Harbour');
    typesDraft($this->editor, 'post', 'Harbour news', [$harbour->id]);
    typesDraft($this->editor, 'post', 'Market day');

    $this->actingAs($this->editor)
        ->get(route('content.index', ['type' => 'posts', 'tag' => $harbour->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('content/index')
            ->where('chosenTerms', ['tag' => 'Harbour'])
            ->where('filters.tag', $harbour->id)
            ->where('filtered', true)
            ->has('rows', 1)
            ->where('rows.0.title', 'Harbour news'));
});

it('names nothing for a category, which its own select shows, nor for a list with no term filter', function (): void {
    $transport = taxonomyTerm('category', 'Transport');

    foreach ([['category' => $transport->id], []] as $query) {
        $this->actingAs($this->editor)
            ->get(route('content.index', ['type' => 'posts', ...$query]))
            ->assertInertia(fn (Assert $page) => $page->where('chosenTerms', []));
    }
});

it('leaves out a tag filter whose term is not a tag, or has gone', function (string $case): void {
    $transport = taxonomyTerm('category', 'Transport');
    $id = $case === 'a category' ? $transport->id : strtolower((string) Str::ulid());

    $this->actingAs($this->editor)
        ->get(route('content.index', ['type' => 'posts', 'tag' => $id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('chosenTerms', [])
            ->where('filters.tag', $id));
})->with(['a category', 'one that has gone']);

it('keeps a flat taxonomy\'s filter when another filter applies, and names it in its own filter, which removes it', function (): void {
    expect(uiComponent('pages/content/index.tsx'))
        ->toMatch('/\(taxonomy\) => !taxonomy\.hierarchical/')
        ->toMatch('/flat\.map\(\s*\(taxonomy\) =>\s*\(\{[\s\S]*?<TermSearchFilter/')
        ->toContain('valueName={page.chosenTerms[taxonomy.key]}')
        ->and(uiComponent('components/list/filter-row.tsx'))
        ->toContain("valueName ?? __('Not found')")
        ->toContain('next.value === ALL ? null : next.value');
});
