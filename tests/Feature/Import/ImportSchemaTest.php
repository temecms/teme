<?php

declare(strict_types=1);

use App\Domain\Import\ImportMessageLevel;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->source = ImportSource::query()->create([
        'address' => 'https://old.example',
        'api_root' => 'https://old.example/wp-json/',
        'user_name' => 'ada',
        'credential' => 'abcd EFGH 1234 ijkl MNOP 5678',
        'plugin_version' => '0.2.0',
        'api_version' => 1,
        'probed_at' => now(),
    ]);

    $this->run = fn (array $overrides = []): ImportRun => ImportRun::query()->create([
        'source_id' => $this->source->id,
        'kind' => ImportRunKind::Full,
        'state' => ImportRunState::Running,
        'phase' => 'users',
        'phase_token' => strtolower((string) Str::ulid()),
        'high_water_at' => now(),
        'started_at' => now(),
        ...$overrides,
    ]);

    $this->entry = fn (ImportRun $run, array $overrides = []): ImportMapEntry => ImportMapEntry::query()->create([
        'source_id' => $this->source->id,
        'object_type' => 'user',
        'object_id' => '1',
        'teme_type' => 'user',
        'teme_id' => '7',
        'status' => ImportStatus::Imported,
        'outcome' => ImportOutcome::Created,
        'source_hash' => str_repeat('a', 64),
        'run_id' => $run->id,
        'imported_at' => now(),
        ...$overrides,
    ]);
});

it('keeps the application password encrypted at rest, and out of the source\'s array and JSON', function (): void {
    $stored = (string) DB::table('import_sources')->where('id', $this->source->id)->value('credential');

    expect($stored)->not->toContain('abcd')
        ->and(Crypt::decryptString($stored))->toBe('abcd EFGH 1234 ijkl MNOP 5678')
        ->and($this->source->fresh()->credential)->toBe('abcd EFGH 1234 ijkl MNOP 5678')
        ->and($this->source->toArray())->not->toHaveKey('credential')
        ->and($this->source->toJson())->not->toContain('abcd');
});

it('refuses a row the import tables never hold', function (string $case): void {
    $run = ($this->run)();
    $entry = ($this->entry)($run);
    $now = now();

    $insert = match ($case) {
        'kind' => fn () => DB::table('import_runs')->insert(['id' => strtolower((string) Str::ulid()), 'source_id' => $this->source->id, 'kind' => 'partial', 'state' => 'finished', 'high_water_at' => $now, 'started_at' => $now, 'ended_at' => $now]),
        'second running' => fn () => ($this->run)(),
        'no token' => fn () => DB::table('import_runs')->where('id', $run->id)->update(['phase_token' => null]),
        'stopped without a reason' => fn () => DB::table('import_runs')->where('id', $run->id)->update(['state' => 'stopped', 'phase_token' => null, 'ended_at' => $now]),
        'type' => fn () => ($this->entry)($run, ['object_type' => 'Post_Type', 'object_id' => '2']),
        'imported without an ID' => fn () => ($this->entry)($run, ['object_id' => '3', 'teme_type' => null, 'teme_id' => null]),
        'mapped twice' => fn () => ($this->entry)($run),
        'code' => fn () => DB::table('import_messages')->insert(['id' => strtolower((string) Str::ulid()), 'run_id' => $run->id, 'map_id' => $entry->id, 'level' => 'warning', 'code' => 'Shortcode', 'message' => 'Removed.', 'context' => '{}', 'created_at' => $now]),
        default => fn () => DB::table('import_sources')->where('id', $this->source->id)->update(['forgotten_at' => $now]),
    };

    expect($insert)->toThrow(QueryException::class);
})->with([
    'a run of a kind Teme has no name for' => ['kind'],
    'a second running import of one site' => ['second running'],
    'a running import without its token' => ['no token'],
    'a stopped import without its reason' => ['stopped without a reason'],
    'an object type that isn\'t lower-case words and hyphens' => ['type'],
    'an imported object without its Teme ID' => ['imported without an ID'],
    'one object mapped twice' => ['mapped twice'],
    'a message code that isn\'t words joined by dots' => ['code'],
    'a forgotten site that still holds its password' => ['forgotten'],
]);

it('writes an empty JSON object, never a list, for empty options, choices, facts and context', function (): void {
    $run = ($this->run)(['options' => [], 'choices' => []]);
    $entry = ($this->entry)($run, ['facts' => []]);
    $message = ImportMessageRecord::query()->create([
        'run_id' => $run->id, 'map_id' => $entry->id, 'level' => ImportMessageLevel::Warning,
        'code' => 'shortcode.removed', 'message' => 'Removed [vc_row].', 'context' => [], 'created_at' => now(),
    ]);

    expect(DB::table('import_runs')->where('id', $run->id)->value('options'))->toBe('{}')
        ->and(DB::table('import_runs')->where('id', $run->id)->value('choices'))->toBe('{}')
        ->and(DB::table('import_map')->where('id', $entry->id)->value('facts'))->toBe('{}')
        ->and(DB::table('import_messages')->where('id', $message->id)->value('context'))->toBe('{}')
        ->and($run->fresh()->state)->toBe(ImportRunState::Running)
        ->and($entry->fresh()->outcome)->toBe(ImportOutcome::Created)
        ->and($message->fresh()->level)->toBe(ImportMessageLevel::Warning);
});

it('keeps an object\'s map row when its run is deleted, and removes everything with its source', function (): void {
    $run = ($this->run)();
    $entry = ($this->entry)($run);
    ImportMessageRecord::query()->create(['run_id' => $run->id, 'map_id' => $entry->id, 'level' => ImportMessageLevel::Error, 'code' => 'object.unexpected', 'message' => 'Failed.', 'context' => [], 'created_at' => now()]);

    $run->delete();

    expect($entry->fresh()->run_id)->toBeNull()
        ->and(DB::table('import_messages')->count())->toBe(0);

    $this->source->delete();

    expect(DB::table('import_map')->count())->toBe(0);
});

it('keeps a picture pass beside the run it follows, and forgets which once that run is deleted (#716)', function (): void {
    $run = ($this->run)(['state' => ImportRunState::Finished, 'phase' => null, 'phase_token' => null, 'ended_at' => now()]);
    $pass = ($this->run)(['kind' => ImportRunKind::MediaPass, 'follows_run_id' => $run->id]);

    expect($pass->fresh()->kind)->toBe(ImportRunKind::MediaPass)
        ->and($pass->fresh()->follows_run_id)->toBe($run->id);

    DB::table('import_runs')->where('id', $run->id)->delete();

    expect($pass->fresh()->follows_run_id)->toBeNull();
});
