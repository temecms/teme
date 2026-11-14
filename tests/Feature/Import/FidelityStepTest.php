<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Operations\Input\RecordFidelityInput;
use App\Domain\Import\Operations\RecordFidelity;
use App\Domain\Import\Phases\Finish\FidelityStep;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\SecondPass\SecondPassStep;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Import\ImportRecords;
use Tests\Fixtures\Import\SecondPassImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('author');
    $this->companion = importFake();
    $this->source = importSource();
    ContentImports::person($this->source->id, $this->author, 3);
});

/**
 * Post $id as the companion sends it, its words "Boats came in at dawn." and WordPress's page $rendered.
 *
 * @return array<string, mixed>
 */
function fstItem(int $id, string $rendered, array $fields = []): array
{
    return ContentImports::wpItem($id, ['slug' => 'harbour-news-'.$id, 'permalink' => "https://old.example/harbour-news-{$id}/", 'rendered' => $rendered, ...$fields]);
}

/**
 * The rendered pages the fidelity check asked for, each by its after_id.
 *
 * @return list<string>
 */
function fstReads(): array
{
    return array_values(array_map(
        fn (array $request): string => $request['query']['after_id'] ?? '0',
        array_filter(test()->companion->requestsTo('content'), fn (array $request): bool => ($request['query']['include'] ?? '') === 'rendered'),
    ));
}

it('flags a post that lost more than 2% of its words, naming where the missing text begins (I-337)', function (): void {
    SecondPassImports::run($this->companion, $this->source, [fstItem(12, '<p>Boats came in at dawn.</p><p>The harbour master rang the bell twice before noon.</p>')], ImportRunKind::Full, null, app(FidelityStep::class));
    $row = ContentImports::row($this->source->id, 12);
    $message = ImportMessageRecord::query()->where('map_id', $row?->id)->where('code', 'fidelity.lost')->firstOrFail();

    expect($message->message)->toBe('About 64.3% of its words on WordPress aren’t here (9 of 14). Missing text begins: “The harbour master rang the bell twice before…”. Compare it with the old page and add what’s missing.')
        ->and($message->level->value)->toBe('warning')
        ->and($row?->facts['checked'])->toBe((string) Content::query()->findOrFail($row?->teme_id)->working_revision_id)
        ->and($row?->facts['lost'])->toEqual(64.3);
});

it('never puts article text in the message’s context', function (): void {
    SecondPassImports::run($this->companion, $this->source, [fstItem(12, '<p>Boats came in at dawn.</p><p>The harbour master rang the bell twice before noon.</p>')], ImportRunKind::Full, null, app(FidelityStep::class));
    $context = ImportMessageRecord::query()->where('code', 'fidelity.lost')->firstOrFail()->context;

    expect($context)->toEqual(['share' => 64.3, 'lost' => 9, 'total' => 14, 'passages' => 1]);
});

it('leaves a post that came across whole unflagged, and marks it checked', function (): void {
    SecondPassImports::run($this->companion, $this->source, [fstItem(12, '<p>Boats came in at dawn.</p>')], ImportRunKind::Full, null, app(FidelityStep::class));
    $row = ContentImports::row($this->source->id, 12);

    expect(ContentImports::codes($row))->not->toContain('fidelity.lost')
        ->and($row?->facts['checked'])->not->toBeNull()
        ->and($row?->facts['lost'])->toEqual(0.0);
});

it('compares the words after the second pass put the reusable blocks in (I-333)', function (): void {
    SecondPassImports::run($this->companion, $this->source, [
        SecondPassImports::using(12, 88, ['rendered' => '<p>Boats came in at dawn.</p><p>Open daily from nine.</p><p>The tide turned at noon.</p>']),
        SecondPassImports::block(88),
    ], ImportRunKind::Full, null, app(SecondPassStep::class), app(FidelityStep::class));

    expect(ContentImports::codes(ContentImports::row($this->source->id, 12)))->not->toContain('fidelity.lost');
});

it('a rerun reads no rendered page and writes nothing (I-1980)', function (): void {
    $items = [fstItem(12, '<p>Boats came in at dawn.</p><p>Gone words here now.</p>')];
    SecondPassImports::run($this->companion, $this->source, $items, ImportRunKind::Full, null, app(FidelityStep::class));
    $reads = count(fstReads());
    $messages = ImportMessageRecord::query()->count();
    $this->travel(5)->seconds();

    SecondPassImports::run($this->companion, $this->source, $items, ImportRunKind::Full, null, app(FidelityStep::class));

    expect(count(fstReads()))->toBe($reads)
        ->and(ImportMessageRecord::query()->count())->toBe($messages);
});

it('reads only the pages that hold an item to check (I-1870)', function (): void {
    $items = array_map(fn (int $id): array => fstItem($id, '<p>Boats came in at dawn.</p>'), range(12, 60));
    SecondPassImports::run($this->companion, $this->source, $items, ImportRunKind::Full, null, app(FidelityStep::class));

    expect(fstReads())->toBe(['11', '31', '51']);

    $this->travel(5)->seconds();
    $items[28] = fstItem(40, '<p>The tide turned at noon.</p>', [
        'blocks' => [wpBlock('core/paragraph', '<p>The tide turned at noon.</p>')],
        'modified_gmt' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
    ]);
    SecondPassImports::run($this->companion, $this->source, $items, ImportRunKind::CatchUp, null, app(FidelityStep::class));

    expect(array_slice(fstReads(), 3))->toBe(['39']);
});

it('never compares an item changed here (I-332)', function (): void {
    // A person's save before the comparison, played by a step at 1150.
    $meddle = new class implements StepPhase
    {
        public function key(): string
        {
            return 'meddle';
        }

        public function order(): int
        {
            return 1150;
        }

        public function label(): string
        {
            return 'Meddle';
        }

        public function runsIn(ImportRunKind $kind): bool
        {
            return true;
        }

        public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
        {
            DB::table('contents')->update(['slug' => 'changed-here']);

            return StepResult::done();
        }
    };

    SecondPassImports::run($this->companion, $this->source, [fstItem(12, '<p>Words the import never brought across at all.</p>')], ImportRunKind::Full, null, $meddle, app(FidelityStep::class));
    $row = ContentImports::row($this->source->id, 12);

    expect(ContentImports::codes($row))->not->toContain('fidelity.lost')
        ->and($row?->facts)->not->toHaveKey('checked')
        ->and(fstReads())->toBe([]);
});

it('marks an item whose rendered page doesn’t come as checked, with nothing to compare', function (): void {
    SecondPassImports::run($this->companion, $this->source, [ContentImports::wpItem(12)], ImportRunKind::Full, null, app(FidelityStep::class));
    $row = ContentImports::row($this->source->id, 12);

    expect($row?->facts['checked'])->not->toBeNull()
        ->and($row?->facts['lost'])->toBeNull()
        ->and(ContentImports::codes($row))->not->toContain('fidelity.lost');
});

it('is registered at 1200, after the address check, in every kind of run', function (): void {
    $step = app(ImportPhases::class)->find('fidelity');

    expect($step)->toBeInstanceOf(FidelityStep::class)
        ->and($step?->order())->toBe(1200)
        ->and($step?->runsIn(ImportRunKind::Full))->toBeTrue()
        ->and($step?->runsIn(ImportRunKind::CatchUp))->toBeTrue()
        ->and(app(ImportPhases::class)->find('check')?->order())->toBeLessThan(1200);
});

it('RecordFidelity writes nothing when authorization fails', function (): void {
    $sourceId = ImportRecords::source(['address' => 'https://other.example', 'api_root' => 'https://other.example/wp-json/']);
    $runId = ImportRecords::run($sourceId, ['state' => 'running', 'phase' => 'fidelity', 'phase_token' => strtolower((string) Str::ulid()), 'ended_at' => null]);
    $imported = ContentImports::import(ContentImports::item($this->author), $this->admin);
    $rowId = ImportRecords::entry($sourceId, $runId, 'post', '12', 'created', ['teme_type' => 'content', 'teme_id' => $imported['contentId'], 'facts' => json_encode(['written' => $imported['written']])]);
    $editor = User::factory()->create()->assignRole('editor');
    $checked = (string) Content::query()->findOrFail($imported['contentId'])->working_revision_id;

    expect(fn (): bool => app(RecordFidelity::class)->execute(new RecordFidelityInput($runId, '12', $checked, 0.0, null, (string) $editor->id)))->toThrow(AuthorizationException::class)
        ->and(DB::table('import_map')->where('id', $rowId)->value('facts'))->not->toContain('checked');
});
