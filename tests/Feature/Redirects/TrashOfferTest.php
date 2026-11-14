<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->trash = fn (User $actor, string $id) => $this->actingAs($actor)->from('/admin/posts')
        ->post(route('content.action', ['type' => 'posts', 'content' => $id, 'action' => 'trash']));
});

it('offers a redirect for a published item\'s address just after it is trashed (I-856)', function (): void {
    $item = addrPublished($this->editor, 'Harbour news');

    ($this->trash)($this->editor, $item->id)
        ->assertRedirect('/admin/posts')
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Moved to the trash. Links to /harbour-news/ no longer reach it.',
            'link' => ['label' => 'Add a redirect for its address', 'href' => '/admin/tools/redirects?from=%2Fharbour-news%2F'],
        ]);
});

it('names the redirect that already leaves from its address, rather than offering another', function (): void {
    $item = addrPublished($this->editor, 'Harbour news');
    rdSaved('/harbour-news', '/news/');

    ($this->trash)($this->editor, $item->id)
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Moved to the trash. Its address, /harbour-news/, has a redirect already.',
            'link' => ['label' => 'See the redirect', 'href' => '/admin/tools/redirects?search=%2Fharbour-news'],
        ]);
});

it('offers nothing to someone who can\'t manage redirects, or after trashing what was never published', function (string $case): void {
    $author = User::factory()->create()->assignRole('author');
    [$actor, $item] = $case === 'an author trashing their own published post'
        ? [$author, addrPublished($author, 'Harbour news')]
        : [$this->editor, lifecycleDraft($this->editor, 'Harbour news')];

    ($this->trash)($actor, $item->id)
        ->assertRedirect('/admin/posts')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Moved to the trash.']);
})->with(['an author trashing their own published post', 'an editor trashing a draft']);
