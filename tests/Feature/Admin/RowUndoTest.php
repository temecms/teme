<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Undo after publishing or trashing from a list row (I-3398): the same route, the opposite action.

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->act = fn (User $actor, string $id, string $action, array $data = []) => $this->actingAs($actor)->from('/admin/posts')
        ->post(route('content.action', ['type' => 'posts', 'content' => $id, 'action' => $action]), $data);
});

/**
 * What a refused Undo must leave alone: the items and their revisions.
 *
 * @return array{contents: list<array<string, mixed>>, revisions: int}
 */
function clRowsNow(): array
{
    return [
        'contents' => DB::table('contents')->orderBy('id')->get(['id', 'status', 'deleted_at', 'published_revision_id', 'working_revision_id'])->map(fn (object $row): array => (array) $row)->all(),
        'revisions' => DB::table('content_revisions')->count(),
    ];
}

it('offers Undo after publishing from a row, and Undo reverts it to a draft', function (): void {
    $draft = lifecycleDraft($this->editor, 'Harbour news');
    $undo = route('content.action', ['type' => 'posts', 'content' => $draft->id, 'action' => 'unpublish'], false);

    $published = ($this->act)($this->editor, $draft->id, 'publish')->assertRedirect('/admin/posts');

    $published->assertInertiaFlash('toast.message', 'Published.')
        ->assertInertiaFlash('toast.undo', ['href' => $undo, 'data' => ['undo' => true]]);
    expect(Content::query()->find($draft->id)->status)->toBe('published');

    $undone = ($this->act)($this->editor, $draft->id, 'unpublish', ['undo' => true])->assertRedirect('/admin/posts');

    $undone->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Reverted to a draft.']);
    expect(Content::query()->find($draft->id)->status)->toBe('draft');
});

it('offers no Undo after publishing a scheduled item, since reverting it to a draft would lose its schedule', function (): void {
    $item = lifecycleDraft($this->editor, 'Harbour news');
    schedFor($item, $this->editor, now()->addDay()->toIso8601String());
    expect(Content::query()->find($item->id)->status)->toBe('scheduled');

    $published = ($this->act)($this->editor, $item->id, 'publish')->assertRedirect('/admin/posts');

    $published->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Published.']);
    expect(Content::query()->find($item->id)->status)->toBe('published');
});

it('offers Undo after trashing an item that offers no redirect, and Undo restores it', function (): void {
    $draft = lifecycleDraft($this->editor, 'Harbour news');

    ($this->act)($this->editor, $draft->id, 'trash')
        ->assertInertiaFlash('toast.message', 'Moved to the trash.')
        ->assertInertiaFlash('toast.undo', [
            'href' => route('content.action', ['type' => 'posts', 'content' => $draft->id, 'action' => 'restore'], false),
            'data' => ['undo' => true],
        ]);

    expect(Content::withTrashed()->find($draft->id)->trashed())->toBeTrue();

    ($this->act)($this->editor, $draft->id, 'restore', ['undo' => true])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Restored.']);

    expect(Content::query()->find($draft->id))->not->toBeNull();
});

it('keeps the redirect offer and no Undo when trashing a published item offers one', function (): void {
    $item = addrPublished($this->editor, 'Harbour news');

    ($this->act)($this->editor, $item->id, 'trash')
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Moved to the trash. Links to /harbour-news/ no longer reach it.',
            'link' => ['label' => 'Add a redirect for its address', 'href' => '/admin/tools/redirects?from=%2Fharbour-news%2F'],
        ]);
});

it('refuses an Undo from someone who may not revert it, and writes nothing', function (): void {
    $post = addrPublished($this->editor, 'Harbour news');
    $contributor = User::factory()->create()->assignRole('contributor');
    $before = clRowsNow();

    ($this->act)($contributor, $post->id, 'unpublish', ['undo' => true])->assertForbidden();

    expect(clRowsNow())->toBe($before)
        ->and(Content::query()->find($post->id)->status)->toBe('published');
});

it('refuses an Undo of a trash from someone who may not restore it, and writes nothing', function (): void {
    $post = lifecycleDraft($this->editor, 'Harbour news');
    ($this->act)($this->editor, $post->id, 'trash');
    $contributor = User::factory()->create()->assignRole('contributor');
    $before = clRowsNow();

    ($this->act)($contributor, $post->id, 'restore', ['undo' => true])->assertForbidden();

    expect(clRowsNow())->toBe($before)
        ->and(Content::withTrashed()->find($post->id)->trashed())->toBeTrue();
});
