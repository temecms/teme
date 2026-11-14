<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\RestoreRevisionInput;
use App\Domain\Contents\Operations\RestoreRevision;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('restores by adding a revision, never by removing one', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('The original.')));
    $original = $content->refresh()->working_revision_id;
    docSave($content->refresh(), $this->author, docWith(docText('A later change.')));
    $count = DB::table('content_revisions')->where('content_id', $content->id)->count();

    app(RestoreRevision::class)->execute(new RestoreRevisionInput($content->id, $original, (string) $this->author->id));

    expect(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($count + 1)
        ->and(DB::table('content_revisions')->where('id', $original)->exists())->toBeTrue()
        ->and($content->refresh()->workingRevision->plain_text)->toContain('The original.')
        ->and($content->refresh()->workingRevision->kind)->toBe('restore');
});

it('does not publish what it restores', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('The original.')));
    $original = $content->refresh()->working_revision_id;
    docSave($content->refresh(), $this->author, docWith(docText('What went live.')));
    listsPublish($content->refresh(), $this->author);
    $published = $content->refresh()->published_revision_id;

    app(RestoreRevision::class)->execute(new RestoreRevisionInput($content->id, $original, (string) $this->author->id));

    // Restoring moves the working revision. The published one is untouched
    // until someone publishes, which is a separate decision.
    $content->refresh();

    expect($content->published_revision_id)->toBe($published)
        ->and($content->status)->toBe('published')
        ->and($content->working_revision_id)->not->toBe($published);
});

it('records which revision was restored', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('The original.')));
    $original = $content->refresh()->working_revision_id;
    docSave($content->refresh(), $this->author, docWith(docText('A later change.')));

    app(RestoreRevision::class)->execute(new RestoreRevisionInput($content->id, $original, (string) $this->author->id));

    $restored = $content->refresh()->workingRevision;

    expect($restored->restored_from_id)->toBe($original)
        ->and($restored->id)->not->toBe($original)
        ->and($restored->author_id)->toBe($this->author->id);
});

it('discards the actor\'s autosave, so their stale draft does not overwrite the restore', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('The original.')));
    $original = $content->refresh()->working_revision_id;
    docSave($content->refresh(), $this->author, docWith(docText('A later change.')));
    edSave($content->id, $this->author, docWith(docText('Half a sentence')));

    app(RestoreRevision::class)->execute(new RestoreRevisionInput($content->id, $original, (string) $this->author->id));

    expect(DB::table('content_autosaves')->where('content_id', $content->id)->where('user_id', $this->author->id)->count())->toBe(0);
});

it('writes nothing when the actor may not edit the article', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('The original.')));
    $original = $content->refresh()->working_revision_id;
    docSave($content->refresh(), $this->author, docWith(docText('A later change.')));
    $working = $content->refresh()->working_revision_id;
    $count = DB::table('content_revisions')->where('content_id', $content->id)->count();
    $stranger = User::factory()->create()->assignRole('author');

    expect(fn () => app(RestoreRevision::class)->execute(new RestoreRevisionInput($content->id, $original, (string) $stranger->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($count)
        ->and($content->refresh()->working_revision_id)->toBe($working);
});

it('answers a 403 to someone who may not edit the article', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $revision = $content->working_revision_id;
    $stranger = User::factory()->create()->assignRole('author');

    $this->actingAs($stranger)
        ->post(route('content.revisions.restore', [$content->id, $revision]))
        ->assertForbidden();

    $this->actingAs($this->author)
        ->post(route('content.revisions.restore', [$content->id, $revision]))
        ->assertRedirect(route('content.edit', $content->id));
});

it('refuses a revision that belongs to another article', function (): void {
    $content = lifecycleDraft($this->editor, 'Harbour festival');
    $other = lifecycleDraft($this->editor, 'Lantern parade');
    docSave($other, $this->editor, docWith(docText('Not this article.')));
    $foreign = $other->refresh()->working_revision_id;
    $working = $content->refresh()->working_revision_id;
    $count = DB::table('content_revisions')->count();

    expect(fn () => app(RestoreRevision::class)->execute(new RestoreRevisionInput($content->id, $foreign, (string) $this->editor->id)))
        ->toThrow(ValidationException::class)
        ->and(DB::table('content_revisions')->count())->toBe($count)
        ->and($content->refresh()->working_revision_id)->toBe($working);
});
