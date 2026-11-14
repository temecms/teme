<?php

declare(strict_types=1);

use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\ReaderPasswords;
use App\Domain\Contents\Visibility;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Operations\Input\MarkVisibilityWaitingInput;
use App\Domain\Import\Operations\MarkVisibilityWaiting;
use App\Domain\Import\Phases\Content\ContentPhase;
use App\Domain\Import\Phases\Content\ContentVisibilityStep;
use App\Domain\Import\Queries\ImportMap;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Import\ContentImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create()->assignRole('author');
    $this->companion = importFake();
    $this->source = importSource();
    ContentImports::person($this->source->id, $this->author, 3);
    $this->private = ContentImports::wpItem(12, ['status' => 'private', 'slug' => 'staff-only', 'title' => 'Staff only', 'permalink' => 'https://old.example/staff-only/']);
    $this->protected = ContentImports::wpItem(13, ['password' => 'harbour-secret-2024', 'slug' => 'members', 'title' => 'Members', 'permalink' => 'https://old.example/members/']);
});

/**
 * The item and its map row as an import made before visibility left them
 * (I-2643): a public draft with no address, its row saying what WordPress
 * had, its fingerprint taken of the draft.
 */
function cvsAsBefore(string $sourceId, int $wordpressId): Content
{
    $row = ContentImports::row($sourceId, $wordpressId);
    $id = (string) $row?->teme_id;

    DB::table('paths')->where(['target_type' => 'content', 'target_id' => $id])->delete();
    DB::table('content_terms')->where(['content_id' => $id, 'scope' => 'published'])->delete();
    DB::table('contents')->where('id', $id)->update(['status' => 'draft', 'visibility' => 'public', 'reader_password' => null, 'published_revision_id' => null]);
    $content = Content::query()->findOrFail($id);
    DB::update("UPDATE import_map SET facts = (facts - 'visibility_marked') || jsonb_build_object('written', ?::text) WHERE id = ?", [ImportedContents::fingerprint($content), $row?->id]);

    return $content;
}

/**
 * A full run of the step and the content phase alone, with these items.
 *
 * @param  list<array<string, mixed>>  $items
 */
function cvsRun(object $test, array $items): void
{
    $test->companion->items('content', $items);
    importPhaseAlone(app(ContentVisibilityStep::class), app(ContentPhase::class));
    importRun($test->source);
}

it('brings again, as private, a private post an earlier run left as a draft (I-2643)', function (): void {
    ContentImports::run($this->companion, $this->source, [$this->private]);
    cvsAsBefore($this->source->id, 12);
    $this->travel(5)->seconds();

    cvsRun($this, [$this->private]);
    $row = ContentImports::row($this->source->id, 12);
    $item = Content::query()->findOrFail($row?->teme_id);

    expect($item->status)->toBe('published')
        ->and($item->visibility)->toBe(Visibility::Private)
        ->and($row?->facts['visibility_marked'])->toBeTrue()
        ->and($row?->outcome)->toBe(ImportOutcome::Updated)
        ->and(addrGet('/staff-only/')->getStatusCode())->toBe(404);
});

it('brings again, with its password, a protected post an earlier run left as a draft (I-2643)', function (): void {
    ContentImports::run($this->companion, $this->source, [$this->protected]);
    cvsAsBefore($this->source->id, 13);
    $this->travel(5)->seconds();

    cvsRun($this, [$this->protected]);
    $item = Content::query()->findOrFail(ContentImports::row($this->source->id, 13)?->teme_id);

    expect($item->status)->toBe('published')
        ->and($item->visibility)->toBe(Visibility::Protected)
        ->and(ReaderPasswords::open($item))->toBe('harbour-secret-2024')
        ->and(addrCurrent('content', $item->id))->toBe('members');
});

it('marks each such row once, and none whose item already has its visibility (I-2643)', function (): void {
    ContentImports::run($this->companion, $this->source, [$this->private, $this->protected, ContentImports::wpItem(14, ['slug' => 'open-news', 'permalink' => 'https://old.example/open-news/'])]);
    cvsAsBefore($this->source->id, 12);
    $mark = fn (): int => scElevated(fn (): int => app(MarkVisibilityWaiting::class)->execute(new MarkVisibilityWaitingInput($this->source->id, null)));

    expect($mark())->toBe(1)
        ->and(ContentImports::row($this->source->id, 12)?->status)->toBe(ImportStatus::NeedsUpdate)
        ->and(ContentImports::row($this->source->id, 13)?->status)->toBe(ImportStatus::Imported)
        ->and(ContentImports::row($this->source->id, 14)?->status)->toBe(ImportStatus::Imported);

    DB::table('import_map')->where('id', ContentImports::row($this->source->id, 12)?->id)->update(['status' => ImportStatus::Imported->value]);

    expect($mark())->toBe(0);
});

it('leaves a draft a person changed here as they left it, and says so (I-2643)', function (): void {
    ContentImports::run($this->companion, $this->source, [$this->private]);
    $item = cvsAsBefore($this->source->id, 12);
    addrRename($item, 'staff-notes', $this->admin);
    $this->travel(5)->seconds();

    cvsRun($this, [$this->private]);
    $after = Content::query()->findOrFail($item->id);

    expect($after->status)->toBe('draft')
        ->and($after->visibility)->toBe(Visibility::Public)
        ->and($after->slug)->toBe('staff-notes')
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 12)))->toContain('post.changed-here');
});

it('keeps no unkeyed hash of a post\'s password on the map, and every other item\'s hash as it was (I-1700)', function (): void {
    $plain = ContentImports::wpItem(14, ['slug' => 'open-news', 'permalink' => 'https://old.example/open-news/']);
    ContentImports::run($this->companion, $this->source, [$this->protected, $plain]);
    $keyed = [...$this->protected, 'password' => hash_hmac('sha256', 'harbour-secret-2024', (string) config('app.key'))];

    expect(ContentImports::row($this->source->id, 13)?->source_hash)->toBe(ImportMap::hashOf($keyed, ['comment_count']))
        ->and(ContentImports::row($this->source->id, 13)?->source_hash)->not->toBe(ImportMap::hashOf($this->protected, ['comment_count']))
        ->and(ContentImports::row($this->source->id, 14)?->source_hash)->toBe(ImportMap::hashOf($plain, ['comment_count']));

    $this->travel(5)->seconds();
    ContentImports::run($this->companion, $this->source, [[...$this->protected, 'password' => 'harbour-secret-2025']]);

    // A new password is a change WordPress made: the item comes again, with it.
    expect(ReaderPasswords::open(Content::query()->findOrFail(ContentImports::row($this->source->id, 13)?->teme_id)))->toBe('harbour-secret-2025');
});

it('runs in full runs only, after the media and before the posts', function (): void {
    $step = app(ContentVisibilityStep::class);

    expect($step->order())->toBe(480)
        ->and($step->key())->toBe('content-visibility')
        ->and($step->runsIn(ImportRunKind::Full))->toBeTrue()
        ->and($step->runsIn(ImportRunKind::CatchUp))->toBeFalse();
});

it('writes nothing when the actor may not import', function (string $who): void {
    ContentImports::run($this->companion, $this->source, [$this->private]);
    cvsAsBefore($this->source->id, 12);
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => app(MarkVisibilityWaiting::class)->execute(new MarkVisibilityWaitingInput($this->source->id, $who === 'an editor' ? (string) $editor->id : null)))
        ->toThrow(AuthorizationException::class)
        ->and(ContentImports::row($this->source->id, 12)?->status)->toBe(ImportStatus::Imported);
})->with(['an editor', 'no one, away from the operator’s shell']);
