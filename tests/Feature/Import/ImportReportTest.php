<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Queries\ImportScreen;
use App\Domain\Import\Source\SourceReader;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    ['source' => $this->source, 'run' => $this->run] = ImportRecords::finishedImport($this->admin->id);

    $this->lines = fn (bool $attentionOnly): array => iterator_to_array(app(ImportScreen::class)->lines($this->run, $attentionOnly), false);

    // The address check, as its phase will register it (I-339), alone: once the real one is registered at boot, a second would share its key.
    $this->check = fn () => importPhaseAlone(new class implements StepPhase
    {
        public function key(): string
        {
            return 'check';
        }

        public function order(): int
        {
            return 1100;
        }

        public function label(): string
        {
            return 'The address check';
        }

        public function runsIn(ImportRunKind $kind): bool
        {
            return true;
        }

        public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
        {
            return StepResult::done();
        }
    });
});

it('seeds a finished import whose counts are its rows’', function (): void {
    $outcomes = DB::table('import_map')->where('run_id', $this->run)->selectRaw('outcome, count(*) AS total')->groupBy('outcome')->pluck('total', 'outcome')->map(fn (mixed $n): int => (int) $n)->all();
    $warnings = DB::table('import_messages')->where('run_id', $this->run)->where('level', 'warning')->count();
    $row = DB::table('import_runs')->where('id', $this->run)->first();

    expect($outcomes)->toEqualCanonicalizing(['created' => 11, 'updated' => 1, 'skipped' => 1, 'failed' => 2])
        ->and([$row->created_count, $row->updated_count, $row->skipped_count, $row->failed_count, $row->warning_count])
        ->toBe([$outcomes['created'], $outcomes['updated'], $outcomes['skipped'], $outcomes['failed'], $warnings]);
});

it('counts a run’s objects by kind and outcome, in the report’s order (I-345)', function (): void {
    expect(app(ImportScreen::class)->report($this->run)['types'])->toBe([
        ['type' => 'user', 'label' => 'People', 'created' => 2, 'updated' => 0, 'skipped' => 1, 'failed' => 0, 'warnings' => 1],
        ['type' => 'term', 'label' => 'Categories and tags', 'created' => 2, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'warnings' => 0],
        ['type' => 'attachment', 'label' => 'Media files', 'created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 1, 'warnings' => 0],
        ['type' => 'post', 'label' => 'Posts and pages', 'created' => 2, 'updated' => 1, 'skipped' => 0, 'failed' => 1, 'warnings' => 1],
        ['type' => 'comment', 'label' => 'Comments', 'created' => 2, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'warnings' => 0],
        ['type' => 'redirect', 'label' => 'Redirects', 'created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'warnings' => 0],
        ['type' => 'file', 'label' => 'File addresses', 'created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'warnings' => 0],
    ]);
});

it('groups a run’s messages by code, errors first, then the most frequent, each with its first words', function (): void {
    ImportRecords::message($this->run, null, 'check.not-found', '/older-page/ answers “not found” on Teme.', 'error', 7);

    $report = app(ImportScreen::class)->report($this->run);

    expect($report['messages'])->toBe([
        ['code' => 'check.not-found', 'level' => 'error', 'levelLabel' => 'Error', 'words' => '/old-page/ answers “not found” on Teme.', 'count' => 2],
        ['code' => 'media.download-failed', 'level' => 'error', 'levelLabel' => 'Error', 'words' => 'The file couldn’t be downloaded (404), so it wasn’t imported. The next run tries again.', 'count' => 1],
        ['code' => 'object.refused', 'level' => 'error', 'levelLabel' => 'Error', 'words' => 'Teme refused it: The title may not be longer than 300 characters.', 'count' => 1],
        ['code' => 'content.shortcode-removed', 'level' => 'warning', 'levelLabel' => 'Warning', 'words' => 'Removed the shortcode [gallery_bank]: its plugin is gone.', 'count' => 1],
        ['code' => 'user.subscriber', 'level' => 'warning', 'levelLabel' => 'Warning', 'words' => 'A subscriber: Teme has no accounts for readers yet, so they weren’t imported.', 'count' => 1],
        ['code' => 'widgets.reported', 'level' => 'warning', 'levelLabel' => 'Warning', 'words' => '=SUM(3) widgets in the sidebar weren’t imported. Set up the theme’s regions again.', 'count' => 1],
    ])
        ->and($report['more'])->toBeFalse()
        ->and($report['problems'])->toBe(4);
});

it('says ready to switch only once the check has run, the import finished and nothing needs attention', function (string $case): void {
    $run = $this->run;

    if ($case === 'ready' || $case === 'before the check exists') {
        // Nothing failed (each failed object's error goes with its row), and no error of the run's own.
        DB::table('import_map')->where('run_id', $run)->where('outcome', 'failed')->delete();
        DB::table('import_messages')->where('run_id', $run)->whereNull('map_id')->where('level', 'error')->delete();
    }

    if ($case === 'still running') {
        $run = ImportRecords::run($this->source, ['state' => 'running', 'phase' => 'check', 'phase_token' => strtolower((string) Str::ulid()), 'ended_at' => null]);
    }

    if ($case === 'before the check exists') {
        importPhaseAlone();
    } else {
        ($this->check)();
    }

    expect(app(ImportScreen::class)->report($run)['ready'])->toBe(match ($case) {
        'ready' => true,
        'with problems' => false,
        default => null,
    });
})->with(['ready', 'with problems', 'still running', 'before the check exists']);

it('writes the full report: a line for each object with its messages, then the run’s own', function (): void {
    $lines = ($this->lines)(false);

    expect($lines[0])->toBe("type,wordpress_id,outcome,teme_type,teme_id,messages\n")
        ->and($lines)->toHaveCount(1 + 15 + 2)
        ->and(array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'post,21,created,content,'))))->toHaveCount(1)
        ->and(implode('', $lines))->toContain(",Removed the shortcode [gallery_bank]: its plugin is gone.\n")
        ->and(implode('', $lines))->toContain("attachment,12,failed,,,\"The file couldn’t be downloaded (404), so it wasn’t imported. The next run tries again.\"\n")
        ->and(array_slice($lines, -2))->toBe([
            "run,,,,,'=SUM(3) widgets in the sidebar weren’t imported. Set up the theme’s regions again.\n",
            "run,,,,,/old-page/ answers “not found” on Teme.\n",
        ]);
});

it('writes only what failed or has a message to the file of what needs attention', function (): void {
    $lines = ($this->lines)(true);
    $objects = array_map(fn (string $line): string => implode(',', array_slice(explode(',', $line), 0, 3)), array_slice($lines, 1, -2));

    expect($objects)->toEqualCanonicalizing(['user,9,skipped', 'post,21,created', 'attachment,12,failed', 'post,23,failed'])
        ->and($lines)->toHaveCount(1 + 4 + 2);
});

it('keeps a spreadsheet from reading a cell as a formula, and quotes a cell with a comma', function (): void {
    $full = implode('', ($this->lines)(false));

    expect($full)->toContain("run,,,,,'=SUM(3) widgets")
        ->and($full)->not->toContain(',=SUM(3)')
        ->and($full)->toContain('"The file couldn’t be downloaded (404), so it wasn’t imported. The next run tries again."');
});

it('names the two files after the site and the day the import started', function (): void {
    $day = DB::table('import_runs')->where('id', $this->run)->value('started_at');
    $date = CarbonImmutable::parse((string) $day)->utc()->format('Y-m-d');

    expect(app(ImportScreen::class)->fileName($this->run, false))->toBe("import-old.example-{$date}.csv")
        ->and(app(ImportScreen::class)->fileName($this->run, true))->toBe("import-old.example-{$date}-attention.csv")
        ->and(app(ImportScreen::class)->fileName(strtolower((string) Str::ulid()), false))->toBeNull();
});

it('lists a site’s imports newest first, each with its counts and its report’s address', function (): void {
    $older = ImportRecords::run($this->source, ['started_at' => now()->subDays(3), 'ended_at' => now()->subDays(3), 'created_count' => 4]);
    $runs = app(ImportScreen::class)->runs($this->source);

    expect(array_map(fn (array $row): array => Arr::except($row, ['startedAt', 'endedAt']), $runs))->toBe([
        ['id' => $this->run, 'kind' => 'full', 'kindLabel' => 'Full import', 'state' => 'finished', 'stateLabel' => 'Finished', 'counts' => ['created' => 11, 'failed' => 2], 'href' => "/admin/tools/import/{$this->source}?run={$this->run}"],
        ['id' => $older, 'kind' => 'full', 'kindLabel' => 'Full import', 'state' => 'finished', 'stateLabel' => 'Finished', 'counts' => ['created' => 4, 'failed' => 0], 'href' => "/admin/tools/import/{$this->source}?run={$older}"],
    ])
        ->and($runs[0]['startedAt'])->toBeString();
});

it('counts the objects of each kind that have a warning, and the run’s errors and warnings, for the report’s columns and chips', function (): void {
    $report = app(ImportScreen::class)->report($this->run);
    $warned = DB::table('import_messages as x')->join('import_map as m', 'm.id', '=', 'x.map_id')
        ->where('x.run_id', $this->run)->where('x.level', 'warning')
        ->selectRaw('m.object_type, count(DISTINCT x.map_id) AS total')->groupBy('m.object_type')->pluck('total', 'object_type')->all();

    expect(array_column($report['types'], 'warnings', 'type'))->toBe(array_map(fn (string $type): int => (int) ($warned[$type] ?? 0), array_column($report['types'], 'type', 'type')))
        ->and($report['levels'])->toBe([
            'error' => DB::table('import_messages')->where('run_id', $this->run)->where('level', 'error')->count(),
            'warning' => DB::table('import_messages')->where('run_id', $this->run)->where('level', 'warning')->count(),
        ]);
});
