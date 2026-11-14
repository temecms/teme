<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Operations\Input\MarkContentWaitingInput;
use App\Domain\Import\Operations\MarkContentWaiting;
use App\Domain\Import\Phases\Content\ContentPhase;
use App\Domain\Import\Phases\Content\ContentWaitingStep;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportResult;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Phases\PreparedObject;
use App\Domain\Import\Phases\SourceRef;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create()->assignRole('author');
    $this->companion = importFake();
    $this->source = importSource();
    ContentImports::person($this->source->id, $this->author, 3);
    $this->photo = mediaUpload($this->admin, mediaJpeg());
});

/**
 * A stand-in for the media phase at order 400: each item of the
 * companion's media list becomes this media item, as an attachment row of
 * the run (the real phase downloads; this test needs only its row).
 */
function cwsMedia(string $mediaId): ListPhase
{
    return new class($mediaId) implements ListPhase
    {
        public function __construct(private string $mediaId) {}

        public function key(): string
        {
            return 'fixture-media';
        }

        public function order(): int
        {
            return 400;
        }

        public function label(): string
        {
            return 'Media';
        }

        public function runsIn(ImportRunKind $kind): bool
        {
            return true;
        }

        public function lists(ImportContext $context): array
        {
            return [new ImportList('media', [], 50)];
        }

        public function identify(ImportList $list, array $item, int|string $key): SourceRef
        {
            return SourceRef::of('attachment', (int) $item['id']);
        }

        public function import(SourceRef $ref, array $item, ImportContext $context, ?PreparedObject $prepared): ImportResult
        {
            return ImportResult::created('media', $this->mediaId);
        }
    };
}

/**
 * A full run of the stand-in media phase, the waiting step and the content
 * phase, over these attachments and posts.
 *
 * @param  list<array<string, mixed>>  $attachments
 * @param  list<array<string, mixed>>  $items
 */
function cwsRun(object $test, array $attachments, array $items): void
{
    $test->companion->items('media', $attachments)->items('content', $items);
    importPhaseAlone(cwsMedia($test->photo->id), app(ContentWaitingStep::class), app(ContentPhase::class));
    importRun($test->source);
}

it('brings a post whose featured image was missing again in the run that brings the image (I-338)', function (): void {
    $post = ContentImports::wpItem(12, ['featured_media' => 41]);
    ContentImports::run($this->companion, $this->source, [$post]);
    expect(ContentImports::row($this->source->id, 12)?->facts['missing'])->toBeTrue();
    $this->travel(5)->seconds();

    cwsRun($this, [['id' => 41, 'modified_gmt' => '2024-05-06 07:08:09']], [$post]);
    $row = ContentImports::row($this->source->id, 12);

    expect(Content::query()->findOrFail($row?->teme_id)->fields[BuiltInFields::FEATURED_IMAGE]['id'] ?? null)->toBe($this->photo->id)
        ->and($row?->facts['missing'])->toBeFalse()
        ->and($row?->status)->toBe(ImportStatus::Imported)
        ->and($row?->outcome)->toBe(ImportOutcome::Updated);
});

it('marks nothing in a run that brought no media, so an unchanged post isn’t written again', function (): void {
    $post = ContentImports::wpItem(12, ['featured_media' => 41]);
    ContentImports::run($this->companion, $this->source, [$post]);
    $first = ContentImports::row($this->source->id, 12);
    $this->travel(5)->seconds();

    cwsRun($this, [], [$post]);
    $again = ContentImports::row($this->source->id, 12);

    expect($again?->run_id)->toBe($first?->run_id)
        ->and($again?->outcome)->toBe(ImportOutcome::Created)
        ->and($again?->facts['missing'])->toBeTrue();
});

it('runs in full runs only, before the posts and after the media', function (): void {
    $step = app(ContentWaitingStep::class);

    expect($step->order())->toBe(490)
        ->and($step->runsIn(ImportRunKind::Full))->toBeTrue()
        ->and($step->runsIn(ImportRunKind::CatchUp))->toBeFalse();
});

it('refuses a run of another source, writing nothing', function (): void {
    $other = ImportRecords::source(['address' => 'https://elsewhere.example']);
    $run = ImportRecords::run($other);

    expect(fn () => scElevated(fn () => app(MarkContentWaiting::class)->execute(new MarkContentWaitingInput($this->source->id, $run, null))))
        ->toThrow(ValidationException::class, 'That run isn’t one of this source’s.');
});

it('writes nothing when the actor may not import', function (string $who): void {
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12, ['featured_media' => 41])]);
    $run = ImportRecords::run($this->source->id);
    ImportRecords::entry($this->source->id, $run, 'attachment', '41', 'created', ['teme_type' => 'media', 'teme_id' => $this->photo->id]);
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => app(MarkContentWaiting::class)->execute(new MarkContentWaitingInput($this->source->id, $run, $who === 'an editor' ? (string) $editor->id : null)))
        ->toThrow(AuthorizationException::class)
        ->and(ContentImports::row($this->source->id, 12)?->status)->toBe(ImportStatus::Imported);
})->with(['an editor', 'no one, away from the operator’s shell']);
