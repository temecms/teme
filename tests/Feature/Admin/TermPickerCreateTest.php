<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
});

it('tells the editor\'s tag picker that someone who may create tags may, and someone who may not may not', function (): void {
    $post = lifecycleDraft($this->author, 'A post');

    $this->actingAs($this->editor)->get("/admin/content/{$post->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.taxonomies.1.key', 'tag')
            ->where('session.taxonomies.1.canCreate', true));

    $this->actingAs($this->author)->get("/admin/content/{$post->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.taxonomies.1.key', 'tag')
            ->where('session.taxonomies.1.canCreate', false));
});

it('tells Quick edit\'s and Bulk edit\'s tag pickers the same', function (): void {
    $this->actingAs($this->editor)->get('/admin/posts')
        ->assertInertia(fn (Assert $page) => $page
            ->where('typeTaxonomies.1.key', 'tag')
            ->where('typeTaxonomies.1.canCreate', true));

    $this->actingAs($this->author)->get('/admin/posts')
        ->assertInertia(fn (Assert $page) => $page
            ->where('typeTaxonomies.1.key', 'tag')
            ->where('typeTaxonomies.1.canCreate', false));
});
