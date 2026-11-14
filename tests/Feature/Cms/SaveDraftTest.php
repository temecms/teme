<?php

declare(strict_types=1);

use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->author = User::factory()->create()->assignRole('author');

    $this->content = app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'post',
        title: 'Original title',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        actorId: (string) $this->author->id,
    ));
});

it('writes a new revision rather than editing the existing one', function (): void {
    $originalRevisionId = $this->content->working_revision_id;

    $updated = app(SaveDraft::class)->execute(new SaveDraftInput(
        contentId: $this->content->id,
        title: 'Edited title',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        expectedRevisionId: $originalRevisionId,
        actorId: (string) $this->author->id,
    ));

    expect($updated->working_revision_id)->not->toBe($originalRevisionId)
        ->and($updated->workingRevision->title)->toBe('Edited title')
        ->and(ContentRevision::find($originalRevisionId)->title)->toBe('Original title')
        ->and(ContentRevision::where('content_id', $this->content->id)->count())->toBe(2);
});

it('leaves the published revision untouched when a published item is edited', function (): void {
    $publishedRevisionId = $this->content->working_revision_id;

    $this->content->update([
        'status' => 'published',
        'published_revision_id' => $publishedRevisionId,
        'published_at' => now(),
    ]);

    $updated = app(SaveDraft::class)->execute(new SaveDraftInput(
        contentId: $this->content->id,
        title: 'Draft edit nobody should see yet',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        expectedRevisionId: $publishedRevisionId,
        actorId: (string) $this->author->id,
    ));

    expect($updated->published_revision_id)->toBe($publishedRevisionId)
        ->and($updated->publishedRevision->title)->toBe('Original title')
        ->and($updated->workingRevision->title)->toBe('Draft edit nobody should see yet')
        ->and($updated->status)->toBe('published');
});

it('refuses a save based on a revision that is no longer current', function (): void {
    $staleRevisionId = $this->content->working_revision_id;

    app(SaveDraft::class)->execute(new SaveDraftInput(
        contentId: $this->content->id,
        title: 'First editor wins',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        expectedRevisionId: $staleRevisionId,
        actorId: (string) $this->author->id,
    ));

    expect(fn () => app(SaveDraft::class)->execute(new SaveDraftInput(
        contentId: $this->content->id,
        title: 'Second editor overwrites',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        expectedRevisionId: $staleRevisionId,
        actorId: (string) $this->author->id,
    )))->toThrow(StaleRevisionException::class);

    expect($this->content->fresh()->workingRevision->title)->toBe('First editor wins');
});

it('refuses an author editing content they do not own', function (): void {
    $stranger = User::factory()->create()->assignRole('author');

    expect(fn () => app(SaveDraft::class)->execute(new SaveDraftInput(
        contentId: $this->content->id,
        title: 'Not mine',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        expectedRevisionId: $this->content->working_revision_id,
        actorId: (string) $stranger->id,
    )))->toThrow(AuthorizationException::class);
});

/**
 * Saves a revision of the item with this title and an empty document.
 */
function addNewSave(Content $content, User $actor, string $title): Content
{
    $content->refresh();

    return app(SaveDraft::class)->execute(new SaveDraftInput(
        contentId: $content->id,
        title: $title,
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        expectedRevisionId: $content->working_revision_id,
        actorId: (string) $actor->id,
    ));
}

it('gives a draft started with no title the address of the first title it is saved with, and only the first (I-425)', function (): void {
    lifecycleDraft($this->author, 'Harbour festival');
    $draft = lifecycleDraft($this->author, '');

    expect($draft->slug)->toBe('untitled');

    // Still no title: the address waits.
    expect(addNewSave($draft, $this->author, '  ')->slug)->toBe('untitled')
        // The first title: taken, so the next free one.
        ->and(addNewSave($draft, $this->author, 'Harbour festival')->slug)->toBe('harbour-festival-2')
        // A later title leaves the address alone.
        ->and(addNewSave($draft, $this->author, 'Harbour festival, the timetable')->slug)->toBe('harbour-festival-2');
});

it('leaves the address of a draft that was given a title, or an address of its own, when it is retitled', function (): void {
    // Made with a title (beforeEach): its address stays.
    expect(addNewSave($this->content, $this->author, 'Edited title')->slug)->toBe('original-title');

    // Made without one, then given an address by hand before any title.
    $draft = lifecycleDraft($this->author, '');
    DB::table('contents')->where('id', $draft->id)->update(['slug' => 'tide-tables']);

    expect(addNewSave($draft, $this->author, 'Tide tables for May')->slug)->toBe('tide-tables');

    // Cleared again after its first title: it keeps the address that title gave it.
    $another = lifecycleDraft($this->author, '');
    addNewSave($another, $this->author, 'Ferry fares');

    expect(addNewSave($another, $this->author, '')->slug)->toBe('ferry-fares')
        ->and(addNewSave($another, $this->author, 'Ferry prices')->slug)->toBe('ferry-fares');
});

it('keeps the address untitled for a draft whose first title is Untitled, rather than counting itself', function (): void {
    $draft = lifecycleDraft($this->author, '');

    expect(addNewSave($draft, $this->author, 'Untitled')->slug)->toBe('untitled');
});
