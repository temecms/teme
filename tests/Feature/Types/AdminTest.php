<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('refuses to act on a page through the posts address', function (): void {
    $page = typesDraft($this->editor, 'page', 'About');

    $this->actingAs($this->editor)
        ->post(route('content.action', ['type' => 'posts', 'content' => $page->id, 'action' => 'trash']))
        ->assertNotFound();

    expect(DB::table('contents')->where('id', $page->id)->value('deleted_at'))->toBeNull();
});

it('keeps the category and tag filters on their old query names', function (): void {
    $category = taxonomyTerm('category', 'News');
    typesDraft($this->editor, 'post', 'Filed', [$category->id]);
    typesDraft($this->editor, 'post', 'Not filed');

    $this->actingAs($this->editor)
        ->get(route('content.index', ['type' => 'posts', 'category' => $category->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.title', 'Filed')
            ->where('rows.0.terms.category.0.name', 'News'));
});

it('shares the types and taxonomies the sidebar draws', function (): void {
    $this->actingAs($this->editor)
        ->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn ($page) => $page
            ->where('contentTypes.0.segment', 'posts')
            ->where('contentTypes.1.segment', 'pages')
            ->where('taxonomies.0.segment', 'categories'));
});

it('keeps every taxonomy in the sidebar on every list, whatever the type uses', function (): void {
    $this->actingAs($this->editor)
        ->get(route('content.index', ['type' => 'pages']))
        ->assertInertia(fn ($page) => $page
            ->has('taxonomies', 2)                 // the shared prop, for the sidebar
            ->has('typeTaxonomies', 0)             // pages use none (I-417): no pickers, no columns
            ->where('labels.plural', 'Pages'));
});
