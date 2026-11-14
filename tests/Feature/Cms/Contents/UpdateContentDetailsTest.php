<?php

declare(strict_types=1);

use App\Domain\Contents\ContentDetailsResult;
use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
});

function listsQuickEdit(Content $content, User $actor, array $changes): ContentDetailsResult
{
    $content->refresh();

    return app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
        ...array_merge(['contentId' => $content->id, 'expectedRevisionId' => $content->working_revision_id, 'actorId' => (string) $actor->id], $changes),
    ));
}

it('renames a draft through a new working revision', function (): void {
    $content = lifecycleDraft($this->author, 'Old');

    $result = listsQuickEdit($content, $this->author, ['title' => 'New']);
    $content->refresh();

    expect($content->title)->toBe('New')
        ->and($content->workingRevision->title)->toBe('New')
        ->and($content->revisions()->count())->toBe(2)
        ->and($result->publishedNow)->toBeFalse();
});

it('publishes the change on a published item with nothing waiting', function (): void {
    $content = lifecycleDraft($this->author, 'Old');
    listsPublish($content, $this->author);

    $result = listsQuickEdit($content, $this->author, ['title' => 'New']);
    $content->refresh();

    expect($result->publishedNow)->toBeTrue()
        ->and($content->published_revision_id)->toBe($content->working_revision_id)
        ->and($content->publishedRevision->title)->toBe('New');
});

it('leaves the live page alone when unpublished changes are waiting', function (): void {
    $content = lifecycleDraft($this->author, 'Live title');
    listsPublish($content, $this->author);
    $content->refresh();
    $live = $content->published_revision_id;

    app(SaveDraft::class)->execute(new SaveDraftInput($content->id, 'Unfinished', ['type' => 'doc', 'content' => []], [], $content->working_revision_id, (string) $this->author->id));

    $result = listsQuickEdit($content, $this->author, ['title' => 'Quick fix']);
    $content->refresh();

    expect($result->draftPending)->toBeTrue()
        ->and($result->publishedNow)->toBeFalse()
        ->and($content->published_revision_id)->toBe($live)
        ->and($content->workingRevision->title)->toBe('Quick fix');
});

it('changes the slug, refusing one another live item uses', function (): void {
    $content = lifecycleDraft($this->author, 'First');
    lifecycleDraft($this->author, 'Taken');

    listsQuickEdit($content, $this->author, ['slug' => 'better-slug']);
    expect($content->fresh()->slug)->toBe('better-slug');

    expect(fn () => listsQuickEdit($content, $this->author, ['slug' => 'taken']))->toThrow(ValidationException::class);
});

it('lets an editor change the author, but not an author', function (): void {
    $content = lifecycleDraft($this->author);

    listsQuickEdit($content, $this->editor, ['authorId' => $this->editor->id]);
    expect($content->fresh()->author_id)->toBe($this->editor->id);

    $mine = lifecycleDraft($this->author, 'Mine');
    expect(fn () => listsQuickEdit($mine, $this->author, ['authorId' => $this->editor->id]))->toThrow(AuthorizationException::class);
    expect($mine->fresh()->author_id)->toBe($this->author->id);
});

it('changes categories through the revision and the projection', function (): void {
    $news = taxonomyTerm('category', 'News');
    $content = lifecycleDraft($this->author);

    listsQuickEdit($content, $this->author, ['terms' => [$news->id]]);

    expect($content->fresh()->workingRevision->terms)->toBe([$news->id]);
});

it('refuses an edit based on a revision that is no longer current', function (): void {
    $content = lifecycleDraft($this->author);
    $stale = $content->working_revision_id;
    listsQuickEdit($content, $this->author, ['title' => 'Newer']);

    expect(fn () => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($content->id, $stale, (string) $this->author->id, title: 'Older')))
        ->toThrow(StaleRevisionException::class);
});

it('writes nothing for someone who may not edit the item', function (): void {
    $content = lifecycleDraft($this->author, 'Theirs');
    $stranger = User::factory()->create()->assignRole('author');

    expect(fn () => listsQuickEdit($content, $stranger, ['title' => 'Hijacked']))->toThrow(AuthorizationException::class);

    expect($content->fresh()->title)->toBe('Theirs')
        ->and($content->revisions()->count())->toBe(1);
});
