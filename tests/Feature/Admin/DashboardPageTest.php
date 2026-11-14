<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the dashboard with the signed-in user\'s name and nothing more of the model', function (): void {
    $user = User::factory()->create(['name' => 'Ada Editor']);

    $this->actingAs($user)
        ->get('/admin')
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('auth.user.name', 'Ada Editor')
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token'));
});

it('sends the person\'s panels and the site figures, and nothing of the models', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('author');
    $draft = lifecycleDraft($author, 'Night market');

    // Creating the draft wrote its first revision, so it is also a recent edit.
    $this->actingAs($author)->get('/admin')
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('panels', fn ($panels): bool => collect($panels)->pluck('key')->all() === ['my_drafts', 'my_recent_edits'])
            // Exactly these five keys: no author_id, working_revision_id or document.
            ->has('panels.0.rows', 1, fn (Assert $row) => $row
                ->where('id', $draft->id)
                ->where('title', 'Night market')
                ->whereType('subtitle', 'string')
                ->whereType('url', 'string')
                ->whereType('at', 'string'))
            // toContain, not an exact list: 1.8 adds Comments to this row.
            ->where('figures', fn ($figures): bool => collect(['post.published', 'post.drafts', 'post.scheduled', 'page.published', 'page.drafts', 'page.scheduled', 'media'])
                ->diff(collect($figures)->pluck('key'))->isEmpty())
            ->where('mainType', ['singular' => 'Post', 'segment' => 'posts', 'canCreate' => true]));
});

it('greets a person with nothing waiting once, rather than with empty panels', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('author');

    // The page itself shows the one message.
    $this->actingAs($author)->get('/admin')
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('panels', []));
});
