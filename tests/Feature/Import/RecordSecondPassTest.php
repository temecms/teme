<?php

declare(strict_types=1);

use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Operations\Input\RecordSecondPassInput;
use App\Domain\Import\Operations\RecordSecondPass;
use App\Domain\Import\Phases\ImportMessage;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('author');
    $this->sourceId = ImportRecords::source();
    $this->runId = ImportRecords::run($this->sourceId, ['state' => 'running', 'phase' => 'second-pass', 'phase_token' => strtolower((string) Str::ulid()), 'ended_at' => null]);
    $this->imported = ContentImports::import(ContentImports::item($this->author), $this->admin);
    $this->rowId = ImportRecords::entry($this->sourceId, $this->runId, 'post', '12', 'created', [
        'teme_type' => 'content',
        'teme_id' => $this->imported['contentId'],
        'facts' => json_encode(['written' => $this->imported['written'], 'references' => [['type' => 'post', 'id' => '88', 'at' => [1]]], 'pending_links' => [], 'pending_canonical' => null]),
    ]);
});

/**
 * RecordSecondPass for WordPress's post 12, with its finished document unless $changes say otherwise.
 *
 * @param  array<string, mixed>  $changes  RecordSecondPassInput's parameters, by name
 */
function rspRecord(array $changes = [], ?User $actor = null): string
{
    return app(RecordSecondPass::class)->execute(new RecordSecondPassInput(...[
        'runId' => test()->runId,
        'objectId' => '12',
        'written' => test()->imported['written'],
        'document' => ContentImports::document('Boats came in at dawn. Open daily from nine.'),
        'fields' => [],
        'passed' => ['from' => 'converted', 'open' => false],
        'messages' => [],
        'actorId' => (string) ($actor ?? test()->admin)->id,
        ...$changes,
    ]));
}

function rspRevisions(): int
{
    return ContentRevision::query()->where('content_id', test()->imported['contentId'])->count();
}

it('writes the item and its row together: the fingerprint after, where it started and what it wrote, its own messages in place of its earlier ones (I-333)', function (): void {
    $from = (string) Content::query()->findOrFail($this->imported['contentId'])->working_revision_id;
    ImportRecords::message($this->runId, $this->rowId, 'second-pass.links-unresolved', 'Earlier words.');
    ImportRecords::message($this->runId, $this->rowId, 'post.slug-changed', 'Kept words.', 'warning', 1);
    $said = new ImportMessage('second-pass.block-missing', 'A reusable block it uses wasn’t brought from WordPress, so that part of it is missing here. Running the import again brings it once the block imports.', ['blocks' => ['90']]);

    $outcome = rspRecord(['passed' => ['from' => $from, 'open' => true, 'blocks' => ['88']], 'messages' => [$said]]);
    $row = ImportMapEntry::query()->findOrFail($this->rowId);
    $content = Content::query()->findOrFail($this->imported['contentId']);

    expect($outcome)->toBe(RecordSecondPass::WROTE)
        ->and(rspRevisions())->toBe(2)
        ->and($row->facts['written'])->toBe(ImportedContents::fingerprint($content))
        ->and($row->facts['passed'])->toEqual(['from' => $from, 'to' => (string) $content->working_revision_id, 'open' => true, 'blocks' => ['88']])
        ->and($row->facts['references'])->toHaveCount(1)
        ->and(ContentImports::codes($row))->toEqualCanonicalizing(['post.slug-changed', 'second-pass.block-missing'])
        ->and(ImportMessageRecord::query()->where('map_id', $this->rowId)->where('code', 'second-pass.block-missing')->value('run_id'))->toBe($this->runId);
});

it('brings a row an earlier run wrote into this run, as updated, when it writes the item (I-345)', function (): void {
    $earlier = ImportRecords::run($this->sourceId);
    DB::table('import_map')->where('id', $this->rowId)->update(['run_id' => $earlier]);

    $outcome = rspRecord();
    $row = ImportMapEntry::query()->findOrFail($this->rowId);

    expect($outcome)->toBe(RecordSecondPass::WROTE)
        ->and($row->run_id)->toBe($this->runId)
        ->and($row->outcome)->toBe(ImportOutcome::Updated)
        ->and($row->status)->toBe(ImportStatus::Imported);
});

it('leaves a row an earlier run’s failure left in its run and status, so the next run tries it again', function (): void {
    $earlier = ImportRecords::run($this->sourceId);
    DB::table('import_map')->where('id', $this->rowId)->update(['run_id' => $earlier, 'status' => 'needs_update', 'outcome' => 'failed']);

    $outcome = rspRecord();
    $row = ImportMapEntry::query()->findOrFail($this->rowId);

    expect($outcome)->toBe(RecordSecondPass::WROTE)
        ->and($row->run_id)->toBe($earlier)
        ->and($row->status)->toBe(ImportStatus::NeedsUpdate)
        ->and($row->outcome)->toBe(ImportOutcome::Failed)
        ->and($row->facts['written'])->toBe(ImportedContents::fingerprint(Content::query()->findOrFail($this->imported['contentId'])));
});

it('writes nothing when the row isn’t as the step read it, or the run isn’t running', function (): void {
    expect(rspRecord(['written' => str_repeat('b', 40)]))->toBe(RecordSecondPass::STALE)
        ->and(rspRevisions())->toBe(1);

    DB::table('import_runs')->where('id', $this->runId)->update(['state' => 'stopped', 'phase_token' => null, 'stop_reason' => 'by-hand', 'ended_at' => now()]);

    expect(rspRecord())->toBe(RecordSecondPass::STALE)
        ->and(rspRevisions())->toBe(1)
        ->and(ImportMapEntry::query()->findOrFail($this->rowId)->facts)->not->toHaveKey('passed');
});

it('records a refusal’s words without writing the item, and settles its row', function (): void {
    $working = (string) Content::query()->findOrFail($this->imported['contentId'])->working_revision_id;
    $refused = new ImportMessage('second-pass.refused', 'Its reusable blocks and links couldn’t be put in, because the result isn’t something Teme can save (too long), so it’s kept as the import first wrote it.');

    $outcome = rspRecord(['document' => null, 'fields' => null, 'passed' => ['from' => $working, 'open' => false], 'messages' => [$refused]]);
    $row = ImportMapEntry::query()->findOrFail($this->rowId);

    expect($outcome)->toBe(RecordSecondPass::SAID)
        ->and(rspRevisions())->toBe(1)
        ->and($row->facts['passed'])->toEqual(['from' => $working, 'to' => $working, 'open' => false, 'blocks' => []])
        ->and($row->outcome)->toBe(ImportOutcome::Created)
        ->and(ContentImports::codes($row))->toBe(['second-pass.refused']);
});

it('writes nothing when authorization fails', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn (): string => rspRecord([], $editor))->toThrow(AuthorizationException::class)
        ->and(rspRevisions())->toBe(1)
        ->and(ImportMapEntry::query()->findOrFail($this->rowId)->facts)->not->toHaveKey('passed');
});
