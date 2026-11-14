<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentAutosave;
use App\Domain\Contents\Operations\CheckpointDraft;
use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\DiscardAutosave;
use App\Domain\Contents\Operations\Input\CheckpointDraftInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\DiscardAutosaveInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SaveAutosaveInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\SaveAutosave;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Queries\EditorSession;
use App\Domain\Contents\SaveAutosaveResult;
use App\Domain\Fields\BuiltInFields;
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

// edSave() lives in tests/Pest.php (backlog B-091).

it('keeps one autosave per person per article', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    edSave($content->id, $this->author, docWith(docText('First')));
    edSave($content->id, $this->author, docWith(docText('Second')));
    edSave($content->id, $this->editor, docWith(docText('Theirs')));

    expect(DB::table('content_autosaves')->where('content_id', $content->id)->count())->toBe(2)
        ->and(DB::table('content_autosaves')->where('user_id', $this->author->id)->value('document'))->toContain('Second');
});

it('writes no revision while someone is typing', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $before = DB::table('content_revisions')->where('content_id', $content->id)->count();

    edSave($content->id, $this->author, docWith(docText('One')));
    edSave($content->id, $this->author, docWith(docText('Two')));
    edSave($content->id, $this->author, docWith(docText('Three')));

    expect(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($before);
});

it('turns the autosave into one revision at a checkpoint, and clears it', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    edSave($content->id, $this->author, docWith(docText('Three days of music by the quay.')));

    app(CheckpointDraft::class)->execute(new CheckpointDraftInput($content->id, (string) $this->author->id));

    $revision = $content->refresh()->workingRevision;

    expect($revision->plain_text)->toContain('Three days of music')
        ->and($revision->kind)->toBe('checkpoint')
        ->and(DB::table('content_autosaves')->where('content_id', $content->id)->where('user_id', $this->author->id)->count())->toBe(0);
});

it('carries an edited title and SEO field from the autosave into the checkpoint', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    edSave($content->id, $this->author, docWith(docText('Three days of music by the quay.')),
        title: 'Harbour festival 2026',
        fields: [BuiltInFields::SEO_TITLE => 'Harbour festival: three days by the quay'],
    );

    app(CheckpointDraft::class)->execute(new CheckpointDraftInput($content->id, (string) $this->author->id));

    $revision = $content->refresh()->workingRevision;

    expect($revision->title)->toBe('Harbour festival 2026')
        ->and($revision->fields[BuiltInFields::SEO_TITLE])->toBe('Harbour festival: three days by the quay')
        ->and($content->title)->toBe('Harbour festival 2026');
});

it('marks a plain save as save, not checkpoint', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    expect(docSave($content, $this->author, docWith(docText('Saved')))->workingRevision->kind)->toBe('save');
});

it('refuses a save based on a revision that is no longer current, and says so', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $stale = $content->working_revision_id;

    // Someone else checkpoints, moving the working revision on.
    edSave($content->id, $this->editor, docWith(docText('Theirs')));
    app(CheckpointDraft::class)->execute(new CheckpointDraftInput($content->id, (string) $this->editor->id));

    $result = edSave($content->id, $this->author, docWith(docText('Mine')), $stale);

    expect($result->stale)->toBeTrue()
        ->and($result->workingRevisionId)->not->toBe($stale)
        ->and(DB::table('content_autosaves')->where('user_id', $this->author->id)->count())->toBe(0);
});

it('reports who else has the article open', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    edSave($content->id, $this->editor, docWith(docText('Theirs')));

    $result = edSave($content->id, $this->author, docWith(docText('Mine')));

    expect($result->otherEditors)->toHaveCount(1)
        ->and($result->otherEditors[0]['name'])->toBe($this->editor->name);
});

it('opens on the person\'s own autosave when there is one, and on the revision when there is not', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    expect(EditorSession::for($content->id, (string) $this->author->id)->fromAutosave)->toBeFalse();

    edSave($content->id, $this->author, docWith(docText('Unsaved work')));
    $session = EditorSession::for($content->id, (string) $this->author->id);

    expect($session->fromAutosave)->toBeTrue()
        ->and(json_encode($session->document))->toContain('Unsaved work');
});

it('does not show one person\'s autosave to another', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    edSave($content->id, $this->author, docWith(docText('Half a sentence')));

    expect(EditorSession::for($content->id, (string) $this->editor->id)->fromAutosave)->toBeFalse();
});

it('writes nothing when the actor may not edit the article', function (): void {
    $content = lifecycleDraft($this->editor, 'Not theirs');
    $contributor = User::factory()->create()->assignRole('contributor');

    expect(fn () => edSave($content->id, $contributor, docWith(docText('Mine now'))))->toThrow(AuthorizationException::class)
        ->and(DB::table('content_autosaves')->count())->toBe(0);
});

it('writes nothing at a checkpoint when the actor may not edit the article', function (): void {
    $content = lifecycleDraft($this->editor, 'Not theirs');
    edSave($content->id, $this->editor, docWith(docText('Theirs')));
    $contributor = User::factory()->create()->assignRole('contributor');
    $revisions = DB::table('content_revisions')->where('content_id', $content->id)->count();

    expect(fn () => app(CheckpointDraft::class)->execute(new CheckpointDraftInput($content->id, (string) $contributor->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($revisions)
        ->and(DB::table('content_autosaves')->where('content_id', $content->id)->count())->toBe(1);
});

it('writes nothing on a discard when the actor may not edit the article', function (): void {
    $content = lifecycleDraft($this->editor, 'Not theirs');
    edSave($content->id, $this->editor, docWith(docText('Theirs')));
    $contributor = User::factory()->create()->assignRole('contributor');

    expect(fn () => app(DiscardAutosave::class)->execute(new DiscardAutosaveInput($content->id, (string) $contributor->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('content_autosaves')->where('content_id', $content->id)->count())->toBe(1);
});

it('refuses a document that does not fit the schema', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    expect(fn () => edSave($content->id, $this->author, docWith(['type' => 'script', 'attrs' => []])))
        ->toThrow(ValidationException::class);
});

it('clears autosaves when the article is published, trashed or deleted', function (string $action): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    edSave($content->id, $this->author, docWith(docText('Unsaved words')));
    $working = $content->refresh()->working_revision_id;
    $revisions = DB::table('content_revisions')->where('content_id', $content->id)->count();
    $act = new ContentActionInput($content->id, (string) $this->author->id);

    if ($action === 'publish') {
        // The autosave is based on the revision being published, so it goes.
        app(PublishRevision::class)->execute(new PublishRevisionInput($content->id, $working, (string) $this->author->id));
    }

    if ($action === 'trash') {
        app(TrashContent::class)->execute($act);
    }

    if ($action === 'delete') {
        app(TrashContent::class)->execute($act);

        // Trashing already cleared it. Put one back, so the cascade is what
        // removes it.
        ContentAutosave::create([
            'content_id' => $content->id, 'user_id' => $this->author->id, 'title' => 'Harbour festival',
            'document' => docWith(docText('Late')), 'fields' => [], 'terms' => [],
            'based_on_revision_id' => $working, 'word_count' => 1, 'saved_at' => now(),
        ]);

        app(DeleteContentPermanently::class)->execute($act);
    }

    expect(DB::table('content_autosaves')->where('content_id', $content->id)->count())->toBe(0);

    if ($action === 'publish') {
        // Publishing never checkpoints: it writes no revision.
        expect(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($revisions);
    }
})->with(['publish', 'trash', 'delete']);

it('keeps an autosave based on another revision when an older one is published', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $older = docSave($content, $this->author, docWith(docText('First draft')))->working_revision_id;
    docSave($content->refresh(), $this->author, docWith(docText('Second draft')));
    edSave($content->id, $this->author, docWith(docText('Newer work')));

    app(PublishRevision::class)->execute(new PublishRevisionInput($content->id, $older, (string) $this->author->id));

    expect(DB::table('content_autosaves')->where('content_id', $content->id)->where('user_id', $this->author->id)->count())->toBe(1);
});

/**
 * A card is unfinished the moment it is inserted: no text, no image, no
 * address. The autosave keeps it, so a reload brings it back; a revision
 * never holds it (B-142).
 */
it('keeps unfinished cards in the autosave, and refuses them at a checkpoint', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $revisions = DB::table('content_revisions')->where('content_id', $content->id)->count();

    edSave($content->id, $this->author, docWith(
        ['type' => 'callout', 'attrs' => ['id' => str_repeat('A', 26), 'tone' => 'note', 'text' => null]],
        ['type' => 'image', 'attrs' => ['id' => str_repeat('B', 26), 'mediaId' => null]],
        ['type' => 'gallery', 'attrs' => ['id' => str_repeat('C', 26), 'mediaIds' => []]],
        ['type' => 'button', 'attrs' => ['id' => str_repeat('D', 26), 'label' => null, 'url' => null, 'contentId' => null]],
    ));

    expect(DB::table('content_autosaves')->where('content_id', $content->id)->count())->toBe(1)
        ->and(fn () => app(CheckpointDraft::class)->execute(new CheckpointDraftInput($content->id, (string) $this->author->id)))
        ->toThrow(ValidationException::class)
        ->and(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($revisions);
});

it('still checks everything but completeness in an autosave', function (array $node): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    expect(fn () => edSave($content->id, $this->author, docWith($node)))->toThrow(ValidationException::class);
})->with([
    'a value not on the list' => [['type' => 'callout', 'attrs' => ['id' => str_repeat('A', 26), 'tone' => 'purple', 'text' => null]]],
    'a card with no id' => [['type' => 'callout', 'attrs' => ['tone' => 'note', 'text' => null]]],
    'an attribute the card does not declare' => [['type' => 'callout', 'attrs' => ['id' => str_repeat('A', 26), 'onclick' => 'x']]],
    'a value of the wrong kind' => [['type' => 'gallery', 'attrs' => ['id' => str_repeat('A', 26), 'mediaIds' => 'all']]],
]);

// One editor's autosave, numbered as the editor numbers them (issue 6).
function numberedSave(string $contentId, User $actor, string $text, int $number, ?string $basedOn = null, string $editor = 'editor-a'): SaveAutosaveResult
{
    $content = Content::query()->findOrFail($contentId);

    return app(SaveAutosave::class)->execute(new SaveAutosaveInput(
        $contentId,
        $content->title,
        docWith(docText($text)),
        [],
        [],
        $basedOn ?? $content->working_revision_id,
        (string) $actor->id,
        editorSession: $editor,
        saveNumber: $number,
    ));
}

it('keeps the newer words when one editor\'s two saves arrive out of order, answering the older as saved (issue 6)', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    $newer = numberedSave($content->id, $this->author, 'The ferry leaves at dawn, and the tide is high.', 2);
    $older = numberedSave($content->id, $this->author, 'The ferry leaves at dawn.', 1);
    $row = DB::table('content_autosaves')->where('user_id', $this->author->id)->sole();

    expect($row->document)->toContain('and the tide is high')
        ->and($older->stale)->toBeFalse()
        ->and($older->savedAt)->toBe($newer->savedAt);
});

it('takes a lower number from another editor session, such as the same person after a reload (issue 6)', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    numberedSave($content->id, $this->author, 'Before the reload.', 7);
    numberedSave($content->id, $this->author, 'After the reload.', 1, editor: 'editor-b');

    expect(DB::table('content_autosaves')->where('user_id', $this->author->id)->value('document'))->toContain('After the reload.');
});

it('keeps the words typed during an explicit save when a save sent before it lands after it (issue 6)', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $first = $content->working_revision_id;

    numberedSave($content->id, $this->author, 'Three days of music.', 1);
    app(CheckpointDraft::class)->execute(new CheckpointDraftInput($content->id, (string) $this->author->id));
    // Typed while the explicit save was on its way, then sent on the new revision.
    numberedSave($content->id, $this->author, 'Three days of music by the quay.', 3);

    // A save sent before the explicit save's answer came back, on the old revision, lands last.
    $late = numberedSave($content->id, $this->author, 'Three days of music by', 2, $first);

    expect($late->stale)->toBeFalse()
        ->and(DB::table('content_autosaves')->where('user_id', $this->author->id)->value('document'))->toContain('by the quay.');
});

it('writes nothing for a numbered save when the actor may not edit the article, and nothing for an older one it ignores (issue 6)', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $contributor = User::factory()->create()->assignRole('contributor');

    expect(fn () => numberedSave($content->id, $contributor, 'Mine now.', 1))->toThrow(AuthorizationException::class)
        ->and(DB::table('content_autosaves')->count())->toBe(0);

    numberedSave($content->id, $this->author, 'Kept.', 5);
    $before = DB::table('content_autosaves')->where('user_id', $this->author->id)->sole();

    numberedSave($content->id, $this->author, 'Ignored.', 4);

    expect(DB::table('content_autosaves')->where('user_id', $this->author->id)->sole())->toEqual($before);
});

it('passes the editor\'s save number through the autosave route, and ignores an older one that arrives late (issue 6)', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $save = fn (string $text, int $number) => $this->actingAs($this->author)->postJson("/admin/content/{$content->id}/autosave", [
        'title' => 'Harbour festival',
        'document' => docWith(docText($text)),
        'fields' => [], 'terms' => [],
        'based_on_revision_id' => $content->working_revision_id,
        'editor_session' => 'editor-a',
        'save_number' => $number,
    ])->assertOk();

    $save('Newer words.', 2);
    $save('Older words.', 1)->assertJsonPath('stale', false);

    expect(DB::table('content_autosaves')->where('user_id', $this->author->id)->value('document'))->toContain('Newer words.');
});
