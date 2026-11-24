<?php

declare(strict_types=1);

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Jobs\ImportListPage;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\AdvanceImportRun;
use App\Domain\Import\Operations\ContinueImportRun;
use App\Domain\Import\Operations\FinishImportRun;
use App\Domain\Import\Operations\ImportObject;
use App\Domain\Import\Operations\Input\AdvanceImportRunInput;
use App\Domain\Import\Operations\Input\ContinueImportRunInput;
use App\Domain\Import\Operations\Input\FinishImportRunInput;
use App\Domain\Import\Operations\Input\ImportObjectInput;
use App\Domain\Import\Operations\Input\RecordImportFailureInput;
use App\Domain\Import\Operations\Input\RecordImportMessagesInput;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\Input\StartMediaPassInput;
use App\Domain\Import\Operations\Input\StopImportRunInput;
use App\Domain\Import\Operations\RecordImportFailure;
use App\Domain\Import\Operations\RecordImportMessages;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Import\Operations\StartMediaPass;
use App\Domain\Import\Operations\StopImportRun;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    importFake();
    importPeople();
    $this->source = importSource($this->admin);
    Queue::fake();

    $this->start = fn (?User $actor = null, ImportRunKind $kind = ImportRunKind::Full, array $options = []): array => app(StartImportRun::class)
        ->execute(new StartImportRunInput($this->source->id, $kind, $options, [], $actor === null ? null : (string) $actor->id));

    $this->started = fn (): ImportRun => ImportRun::query()->findOrFail(scElevated(fn (): array => ($this->start)($this->admin))['runId']);

    $this->tables = fn (): string => collect(['import_runs', 'import_map', 'import_messages', 'security_events', 'job_batches'])
        ->map(fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson())->implode('|');
});

it('starts a run for an administrator who confirmed their password, logging it, and queues its first phase', function (): void {
    $run = ($this->started)();

    expect($run->state)->toBe(ImportRunState::Running)
        ->and($run->kind)->toBe(ImportRunKind::Full)
        ->and($run->phase)->toBe('fixture-people')
        ->and($run->options)->toBe(['pause_ms' => 1000])
        ->and($run->started_by)->toBe($this->admin->id)
        ->and(DB::table('job_batches')->where('name', "import:{$run->id}:fixture-people")->exists())->toBeTrue()
        ->and(Queue::pushed(ImportListPage::class)->first()?->token)->toBe($run->phase_token);

    $event = DB::table('security_events')->where('type', 'import.started')->sole();

    expect(json_decode((string) $event->context, true))->toEqualCanonicalizing(['run' => $run->id, 'source' => $this->source->id, 'kind' => 'full', 'continued' => false]);
});

it('asks a person for their password again before starting or carrying on (I-1707)', function (string $case): void {
    $act = $case === 'start'
        ? fn () => ($this->start)($this->admin)
        : function (): void {
            $run = ($this->started)();
            app(StopImportRun::class)->execute(new StopImportRunInput($run->id, (string) $this->admin->id));
            app(ContinueImportRun::class)->execute(new ContinueImportRunInput($run->id, (string) $this->admin->id));
        };

    expect($act)->toThrow(ElevationRequiredException::class);
})->with(['starting' => ['start'], 'carrying on' => ['continue']]);

it('refuses what a run can\'t start from', function (string $case, string $field, string $words): void {
    match ($case) {
        'running' => ($this->started)(),
        'forgotten' => DB::table('import_sources')->update(['credential' => null, 'forgotten_at' => now()]),
        default => null,
    };

    $act = fn () => scElevated(fn () => ($this->start)(
        $this->admin,
        $case === 'catch-up' ? ImportRunKind::CatchUp : ImportRunKind::Full,
        match ($case) {
            'pause' => ['pause_ms' => 60001], 'option' => ['speed' => 'fast'], 'lanes' => ['media_lanes' => 5], 'no lanes' => ['media_lanes' => 0], default => []
        },
    ));

    expect($act)->toThrow(function (ValidationException $refusal) use ($field, $words): void {
        expect($refusal->errors()[$field][0] ?? null)->toBe($words);
    });
})->with([
    'a second import of a site being imported' => ['running', 'source', 'This site is being imported already.'],
    'a catch-up of a site never imported to the end' => ['catch-up', 'kind', 'A catch-up needs a finished import of this site first.'],
    'a site whose password Teme forgot' => ['forgotten', 'source', 'Teme has forgotten this site’s application password. Connect the site again first.'],
    'a pause longer than a minute' => ['pause', 'options.pause_ms', 'Choose a pause from 0 to 60,000 milliseconds.'],
    'an option Teme doesn\'t have' => ['option', 'options', 'Teme has no import option called speed.'],
    'more files at once than the media setting allows (#701)' => ['lanes', 'options.media_lanes', 'Choose from 1 to 4 files at once.'],
    'no files at once (#701)' => ['no lanes', 'options.media_lanes', 'Choose from 1 to 4 files at once.'],
]);

it('writes nothing for anyone who may not import', function (string $operation): void {
    $run = ($this->started)();
    $token = (string) $run->phase_token;
    $context = app(ImportRuns::class)->context($run, JobActor::person($this->editor->id));
    $item = ['id' => 1, 'login' => 'ada', 'display_name' => 'Ada Lovelace', 'roles' => ['administrator']];
    $editor = (string) $this->editor->id;
    // An import that brought the articles first, finished, for a picture pass to follow.
    $articles = ImportRun::query()->create(['source_id' => $this->source->id, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Finished, 'options' => ['pause_ms' => 0], 'choices' => ['media' => 'later'], 'started_at' => now(), 'high_water_at' => now(), 'ended_at' => now()]);
    $before = ($this->tables)();

    $act = match ($operation) {
        'start' => fn () => scElevated(fn () => ($this->start)($this->editor)),
        'start with lanes' => fn () => scElevated(fn () => ($this->start)($this->editor, ImportRunKind::Full, ['media_lanes' => 3])),
        'continue' => fn () => scElevated(fn () => app(ContinueImportRun::class)->execute(new ContinueImportRunInput($run->id, $editor))),
        'stop' => fn () => app(StopImportRun::class)->execute(new StopImportRunInput($run->id, $editor)),
        'pass' => fn () => app(StartMediaPass::class)->execute(new StartMediaPassInput((string) $articles->id, $editor)),
        'advance' => fn () => app(AdvanceImportRun::class)->execute(new AdvanceImportRunInput($run->id, $token, 'fixture-notes', strtolower((string) Str::ulid()), $editor)),
        'finish' => fn () => app(FinishImportRun::class)->execute(new FinishImportRunInput($run->id, $token, $editor)),
        'import an object' => fn () => app(ImportObject::class)->execute(new ImportObjectInput($token, 'fixture-people', $context, SourceRef::of('user', 1), $item, ImportMap::hashOf($item), null)),
        'record a failure' => fn () => app(RecordImportFailure::class)->execute(new RecordImportFailureInput($run->id, $token, SourceRef::of('user', 1), ImportMessage::error('fixture.refused', 'Refused.'), $editor)),
        default => fn () => app(RecordImportMessages::class)->execute(new RecordImportMessagesInput($run->id, $token, [new ImportMessage('site.title', 'A note.')], $editor)),
    };

    expect($act)->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(($this->tables)())->toBe($before)
        ->and(DB::table('fixture_people')->count())->toBe(0);
})->with([
    'starting' => ['start'],
    'starting with media lanes (#701)' => ['start with lanes'],
    'carrying on' => ['continue'],
    'starting a picture pass (#716)' => ['pass'],
    'stopping' => ['stop'],
    'advancing' => ['advance'],
    'finishing' => ['finish'],
    'importing an object' => ['import an object'],
    'recording a failure' => ['record a failure'],
    'recording messages' => ['record messages'],
]);

it('stops a running import by hand, and carries it on from the phase it stopped in', function (): void {
    $run = ($this->started)();

    app(StopImportRun::class)->execute(new StopImportRunInput($run->id, (string) $this->admin->id));
    $stopped = $run->fresh();

    expect($stopped->state)->toBe(ImportRunState::Stopped)
        ->and($stopped->stop_reason)->toBe('by-hand')
        ->and($stopped->stop_message)->toBe('Stopped on request.')
        ->and($stopped->phase_token)->toBeNull()
        ->and(DB::table('security_events')->where('type', 'import.ended')->count())->toBe(1);

    scElevated(fn () => app(ContinueImportRun::class)->execute(new ContinueImportRunInput($run->id, (string) $this->admin->id)));
    $carried = $run->fresh();

    expect($carried->state)->toBe(ImportRunState::Running)
        ->and($carried->phase)->toBe('fixture-people')
        ->and($carried->phase_token)->not->toBe($run->phase_token)
        ->and($carried->stop_reason)->toBeNull()
        ->and(Queue::pushed(ImportListPage::class)->last()?->token)->toBe($carried->phase_token)
        ->and(DB::table('security_events')->where('type', 'import.started')->where('context->continued', true)->count())->toBe(1);
});

it('carries on an import from an export file without a password, and refuses once the file is gone', function (): void {
    $run = ($this->started)();
    app(StopImportRun::class)->execute(new StopImportRunInput($run->id, (string) $this->admin->id));
    $file = ImportFile::query()->create([
        'source_id' => $this->source->id, 'uploaded_by' => $this->admin->id, 'name' => 'site.xml',
        'size_bytes' => 10, 'received_bytes' => 10, 'state' => 'ready',
    ]);
    DB::table('import_runs')->where('id', $run->id)->update(['file_id' => $file->id]);
    DB::table('import_sources')->update(['credential' => null, 'forgotten_at' => now()]);
    $continue = fn () => scElevated(fn () => app(ContinueImportRun::class)->execute(new ContinueImportRunInput($run->id, (string) $this->admin->id)));

    $continue();

    expect($run->fresh()->state)->toBe(ImportRunState::Running);

    app(StopImportRun::class)->execute(new StopImportRunInput($run->id, (string) $this->admin->id));
    DB::table('import_files')->where('id', $file->id)->update(['deleted_at' => now()]);

    expect($continue)->toThrow(ValidationException::class, 'Teme no longer has that export file. Upload it again.')
        ->and($run->fresh()->state)->toBe(ImportRunState::Stopped);
});

it('refuses to carry on an import that didn\'t stop, or to stop one that isn\'t running', function (): void {
    $run = ($this->started)();

    expect(fn () => scElevated(fn () => app(ContinueImportRun::class)->execute(new ContinueImportRunInput($run->id, (string) $this->admin->id))))
        ->toThrow(ValidationException::class, 'Only an import that stopped can be carried on.');

    app(StopImportRun::class)->execute(new StopImportRunInput($run->id, (string) $this->admin->id));

    expect(fn () => app(StopImportRun::class)->execute(new StopImportRunInput($run->id, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'This import isn’t running.');
});

it('turns the site\'s failures into work for the next run', function (): void {
    $finished = ImportRun::query()->create([
        'source_id' => $this->source->id, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Finished,
        'high_water_at' => now(), 'started_at' => now(), 'ended_at' => now(),
    ]);
    ImportMapEntry::query()->create([
        'source_id' => $this->source->id, 'object_type' => 'user', 'object_id' => '2', 'status' => ImportStatus::Failed,
        'outcome' => ImportOutcome::Failed, 'run_id' => $finished->id, 'imported_at' => now(),
    ]);

    ($this->started)();

    expect(ImportMapEntry::query()->sole()->status)->toBe(ImportStatus::NeedsUpdate);
});

it('gives a catch-up what changed since an hour before the last finished import (I-345)', function (): void {
    $mark = CarbonImmutable::now()->subDays(2)->startOfSecond();
    $finished = ImportRun::query()->create([
        'source_id' => $this->source->id, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Finished,
        'high_water_at' => $mark, 'started_at' => $mark, 'ended_at' => $mark->addHour(),
    ]);

    $catchUp = ImportRun::query()->findOrFail(scElevated(fn (): array => ($this->start)($this->admin, ImportRunKind::CatchUp))['runId']);
    $context = app(ImportRuns::class)->context($catchUp, JobActor::person($this->admin->id));

    expect($finished->id)->not->toBe($catchUp->id)
        ->and($context->kind)->toBe(ImportRunKind::CatchUp)
        ->and($context->modifiedAfter?->utc()->format('Y-m-d H:i:s'))->toBe($mark->subHour()->utc()->format('Y-m-d H:i:s'))
        ->and($context->pauseMs)->toBe(1000)
        ->and($context->actorId())->toBe((string) $this->admin->id);
});

it('leaves a picture pass out of the latest and the last finished import, so catch-up and the report read the import it follows (#716)', function (): void {
    $run = ($this->started)();
    DB::table('import_runs')->where('id', $run->id)->update(['state' => 'finished', 'phase' => null, 'phase_token' => null, 'ended_at' => now()]);
    $pass = ImportRun::query()->create([
        'source_id' => $this->source->id, 'kind' => ImportRunKind::MediaPass, 'state' => 'finished', 'follows_run_id' => $run->id,
        'high_water_at' => now(), 'started_at' => now()->addMinute(), 'ended_at' => now()->addMinutes(2),
    ]);
    $runs = app(ImportRuns::class);

    expect($runs->latest($this->source->id)?->id)->toBe($run->id)
        ->and($runs->lastFinished($this->source->id)?->id)->toBe($run->id)
        ->and($runs->passOf($run->id)?->id)->toBe($pass->id)
        ->and(ImportRunKind::MediaPass->label())->toBe('Pictures');
});
