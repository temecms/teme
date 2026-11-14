<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->bulk = fn (User $actor, string $type, string $action, array $ids) => $this->actingAs($actor)
        ->from("/admin/{$type}")
        ->post(route('content.bulk', ['type' => $type]), ['action' => $action, 'ids' => $ids]);
});

it('says what each of the bar\'s buttons did, naming the type', function (string $action, string $message): void {
    $one = lifecycleDraft($this->editor, 'Harbour news');
    $two = lifecycleDraft($this->editor, 'Market day');

    if ($action !== 'trash') {
        ($this->bulk)($this->editor, 'posts', 'trash', [$one->id, $two->id]);
    }

    ($this->bulk)($this->editor, 'posts', $action, [$one->id, $two->id])
        ->assertRedirect('/admin/posts')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $message]);
})->with([
    'moving to the trash' => ['trash', 'Moved 2 posts to the trash.'],
    'restoring' => ['restore', 'Restored 2 posts.'],
    'deleting permanently' => ['delete', 'Deleted 2 posts permanently.'],
]);

it('counts one item in the singular, and names Pages on Pages', function (): void {
    $about = lifecycleDraft($this->editor, 'About us', 'page');

    ($this->bulk)($this->editor, 'pages', 'trash', [$about->id])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Moved 1 page to the trash.']);
});

it('keeps "items changed" for the actions the bar no longer offers, and for nothing done', function (): void {
    $tag = taxonomyTerm('tag', 'Featured');
    $mine = lifecycleDraft($this->author, 'Mine');
    $theirs = lifecycleDraft($this->editor, 'Theirs');

    $this->actingAs($this->author)->from('/admin/posts')
        ->post(route('content.bulk', ['type' => 'posts']), ['action' => 'add_term', 'term_id' => $tag->id, 'ids' => [$mine->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '1 item changed.']);

    ($this->bulk)($this->author, 'posts', 'trash', [$theirs->id])
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => '0 items changed.']);

    expect(Content::query()->find($theirs->id))->not->toBeNull();
});

it('gives the bulk bar buttons, not a select, and names the type in its count', function (): void {
    expect(uiComponent('pages/content/index.tsx'))
        ->not->toContain('aria-label="Bulk action"')
        ->not->toContain('Apply to {selected.length}')
        ->not->toContain('function BulkForm(')
        ->toContain('Edit…')
        ->toContain("bulk('trash')")
        ->toContain("bulk('restore')")
        ->toContain('setDeleting(true)')
        ->toContain('noun={{ one: singular, many: plural }}')
        ->and(uiComponent('components/list/bulk-bar.tsx'))->toContain('noun.one')
        ->toContain('noun.many');
});

it('names each item before deleting permanently in bulk, an untitled one too, and never says "for good" (I-646)', function (): void {
    expect(uiComponent('pages/content/index.tsx'))
        ->toContain('All their revisions go with them. This can’t be undone.')
        ->toContain('${plural} permanently?`')
        ->toContain("'Untitled'")
        ->toContain(' to delete`}')
        ->not->toContain('for good')
        ->and(uiComponent('components/list/confirm-items.tsx'))
        ->toContain('data-test="confirm-items"')
        ->toContain('data-test="confirm-items-list"')
        ->toContain('aria-label={listLabel}')
        ->toContain('tabIndex={0}');
});

it('keeps Undo’s signpost where it will attach (I-1450)', function (): void {
    expect((string) file_get_contents(app_path('Http/Admin/Contents/BulkContentController.php')))->toContain('Later (I-1450)');
});
