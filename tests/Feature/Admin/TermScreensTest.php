<?php

declare(strict_types=1);

use App\Domain\Taxonomy\Models\Term;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('lists categories by name, 50 at a time, with a cursor to the next page', function (): void {
    foreach (range(1, 55) as $n) {
        taxonomyTerm('category', sprintf('Category %02d', $n));
    }

    $first = $this->actingAs($this->editor)->get('/admin/categories');

    $first->assertInertia(fn (Assert $page) => $page
        ->component('terms/index')
        ->where('taxonomy', 'categories')
        ->has('rows', 50)
        ->where('rows.0.name', 'Category 01')
        ->whereNot('older', null));

    $next = $first->viewData('page')['props']['older'];

    $this->actingAs($this->editor)
        ->get('/admin/categories?after='.$next)
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 5)
            ->where('rows.0.name', 'Category 51')
            ->where('older', null));
});

it('shows each category\'s path', function (): void {
    $parent = taxonomyTerm('category', 'World');
    taxonomyTerm('category', 'Africa', $parent->id);

    $this->actingAs($this->editor)
        ->get('/admin/categories?search=Afr')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.path', 'World › Africa'));
});

it('creates, renames and deletes through the Operations', function (): void {
    $this->actingAs($this->editor)->post('/admin/tags', ['name' => 'Breaking'])->assertSessionHasNoErrors();
    $tag = Term::query()->where('slug', 'breaking')->sole();

    $this->actingAs($this->editor)->put("/admin/tags/{$tag->id}", ['name' => 'Breaking news', 'slug' => 'breaking-news'])->assertSessionHasNoErrors();
    expect($tag->fresh()->name)->toBe('Breaking news');

    $this->actingAs($this->editor)->delete("/admin/tags/{$tag->id}")->assertSessionHasNoErrors();
    expect(Term::query()->find($tag->id))->toBeNull();
});

it('keeps a category\'s parent and description when an edit doesn\'t send them (issue 84)', function (): void {
    $transport = taxonomyTerm('category', 'Transport');
    $rail = taxonomyTerm('category', 'Rail', (string) $transport->id);
    $rail->update(['description' => 'Trains and stations.']);

    $this->actingAs($this->editor)->put("/admin/categories/{$rail->id}", ['name' => 'Railways', 'slug' => 'railways'])->assertSessionHasNoErrors();

    $rail->refresh();

    expect($rail->name)->toBe('Railways')
        ->and((string) $rail->parent_id)->toBe((string) $transport->id)
        ->and($rail->description)->toBe('Trains and stations.');
});

it('still clears a parent or a description an edit sends empty', function (): void {
    $transport = taxonomyTerm('category', 'Transport');
    $rail = taxonomyTerm('category', 'Rail', (string) $transport->id);
    $rail->update(['description' => 'Trains and stations.']);

    $this->actingAs($this->editor)->put("/admin/categories/{$rail->id}", ['name' => 'Rail', 'slug' => 'rail', 'parent_id' => '', 'description' => ''])->assertSessionHasNoErrors();

    $rail->refresh();

    expect($rail->parent_id)->toBeNull()
        ->and($rail->description)->toBeNull();
});

it('forbids the term screens to people who may not manage terms', function (): void {
    $author = User::factory()->create()->assignRole('author');

    $this->actingAs($author)->get('/admin/categories')->assertForbidden();
    $this->actingAs($author)->post('/admin/categories', ['name' => 'Mine'])->assertForbidden();
});

it('returns 404 for an unknown taxonomy', function (): void {
    $this->actingAs($this->editor)->get('/admin/genres')->assertNotFound();
});

it('lets anyone who writes content search terms by name prefix, 20 at most', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');

    foreach (range(1, 25) as $n) {
        taxonomyTerm('tag', "Football {$n}");
    }
    taxonomyTerm('tag', 'Basketball');

    $this->actingAs($contributor)
        ->getJson('/admin/terms/search?taxonomy=tag&q=foot')
        ->assertOk()
        ->assertJsonCount(20)
        ->assertJsonPath('0.name', 'Football 1');
});
