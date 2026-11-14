<?php

declare(strict_types=1);

use App\Domain\Import\ImportEngine;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Jobs\ImportListPage;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\ContinueImportRun;
use App\Domain\Import\Operations\ForgetImportSource;
use App\Domain\Import\Operations\Input\ContinueImportRunInput;
use App\Domain\Import\Operations\Input\ForgetImportSourceInput;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\Input\StopImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Import\Operations\StopImportRun;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Tracking\ImportJobKind;
use App\Domain\Jobs\JobKinds;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Jobs\Operations\Input\RetryJobInput;
use App\Domain\Jobs\Operations\RetryJob;
use App\Domain\Jobs\Queries\TrackedJobList;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Jobs\JobsFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->grace = User::factory()->create(['name' => 'Grace Admin'])->assignRole('administrator');
    importFake();
    importPeople();
    $this->source = importSource($this->admin);

    // The run's jobs, newest first, as Tools › Jobs draws them.
    $this->jobs = fn (string $runId): array => app(TrackedJobList::class)->ofRun('import', $runId);

    // A run started by $as (or the operator) with the queue held, so its jobs wait for the test.
    $this->start = function (?User $as = null): ImportRun {
        Queue::fake();
        $result = scElevated(fn (): array => app(StartImportRun::class)->execute(
            new StartImportRunInput($this->source->id, ImportRunKind::Full, ['pause_ms' => 0], [], $as === null ? null : (string) $as->id),
        ));

        return ImportRun::query()->findOrFail($result['runId']);
    };

    // The run's phase ends, as its batch reports it, with the run's actor.
    $this->phaseEnds = fn (ImportRun $run, JobActor $actor) => app(ImportEngine::class)->phaseEnded($run->id, (string) $run->fresh()->phase_token, $actor, 0);

    // The run stops by itself, as a page that failed three times stops it.
    $this->stopsByItself = fn (ImportRun $run): bool => app(StopImportRun::class)->execute(new StopImportRunInput(
        $run->id,
        (string) $this->admin->id,
        (string) $run->fresh()->phase_token,
        'page-failed',
        'Part of this import failed three times, so it stopped. Run it again to carry on from where it stopped; the application log has the details.',
    ));

    // The titles in a person's inbox, oldest first.
    $this->inbox = fn (User $person): array => DB::table('inbox_items')->where('user_id', $person->id)->orderBy('created_at')->orderBy('id')
        ->pluck('body')->map(fn (mixed $body): string => (string) json_decode((string) $body, true)['title'])->all();
});

it('starts the run’s job as the run begins, labelled for the site, its steps as its total (I-1714)', function (): void {
    $run = ($this->start)($this->admin);

    expect(($this->jobs)($run->id))->toHaveCount(1)
        ->and(($this->jobs)($run->id)[0])->toMatchArray([
            'kind' => 'import',
            'label' => 'Import from old.example',
            'state' => 'running',
            'total' => 2,
            'done' => 0,
            'message' => 'Now: People',
            'startedBy' => ['kind' => 'person', 'name' => 'Ada Admin'],
        ]);
});

it('reports each step the run reaches as its phase ends', function (): void {
    $run = ($this->start)($this->admin);

    ($this->phaseEnds)($run, JobActor::person($this->admin->id));

    expect(($this->jobs)($run->id)[0])->toMatchArray(['state' => 'running', 'done' => 1, 'total' => 2, 'message' => 'Now: Notes', 'percent' => 50]);
});

it('finishes the run’s job with the run’s counts as the run finishes on the synchronous queue, and tells whoever started it', function (): void {
    $run = importRun($this->source, $this->admin);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(($this->jobs)($run->id))->toHaveCount(1)
        ->and(($this->jobs)($run->id)[0])->toMatchArray([
            'state' => 'finished',
            'message' => '3 created, 0 updated, 1 skipped, 1 failed, 4 with warnings.',
            'done' => 2,
            'percent' => 100,
        ])
        ->and(($this->inbox)($this->admin))->toBe(['Finished: Import from old.example']);
});

it('fails the run’s job in a phase’s own words when the phase stops the run on the synchronous queue', function (): void {
    $run = importRun($this->source, $this->admin, choices: ['stop-step' => true]);

    expect($run->state)->toBe(ImportRunState::Stopped)
        ->and(($this->jobs)($run->id)[0])->toMatchArray(['state' => 'failed', 'done' => 1, 'message' => 'Stopped by the fixture step.'])
        ->and(($this->inbox)($this->admin))->toBe(['Failed: Import from old.example']);
});

it('fails the run’s job in the run’s own words once the run stops by itself, and tells whoever started it', function (): void {
    $run = ($this->start)($this->admin);
    ($this->stopsByItself)($run);

    expect(($this->jobs)($run->id)[0])->toMatchArray([
        'state' => 'failed',
        'message' => 'Part of this import failed three times, so it stopped. Run it again to carry on from where it stopped; the application log has the details.',
        'canRetry' => true,
    ])
        ->and(($this->inbox)($this->admin))->toBe(['Failed: Import from old.example']);
});

it('leaves a cancelled job as its cancel left it when the run then stops', function (): void {
    $run = ($this->start)($this->admin);

    app(CancelJob::class)->execute(new CancelJobInput(($this->jobs)($run->id)[0]['id'], (string) $this->admin->id));

    expect($run->fresh()->state)->toBe(ImportRunState::Stopped)
        ->and(($this->jobs)($run->id))->toHaveCount(1)
        ->and(($this->jobs)($run->id)[0])->toMatchArray(['state' => 'cancelled', 'message' => 'Cancelled by Ada Admin.']);
});

it('takes the same job up again when a run that stopped by itself is carried on', function (): void {
    $run = ($this->start)($this->admin);
    ($this->stopsByItself)($run);
    $first = ($this->jobs)($run->id)[0]['id'];

    scElevated(fn (): array => app(ContinueImportRun::class)->execute(new ContinueImportRunInput($run->id, (string) $this->admin->id)));

    expect(($this->jobs)($run->id))->toHaveCount(1)
        ->and(($this->jobs)($run->id)[0])->toMatchArray(['id' => $first, 'state' => 'running', 'message' => 'Now: People'])
        ->and($run->fresh()->state)->toBe(ImportRunState::Running);
});

it('starts a new job when the run’s last one was cancelled', function (): void {
    $run = ($this->start)($this->admin);
    app(CancelJob::class)->execute(new CancelJobInput(($this->jobs)($run->id)[0]['id'], (string) $this->admin->id));

    scElevated(fn (): array => app(ContinueImportRun::class)->execute(new ContinueImportRunInput($run->id, (string) $this->admin->id)));

    expect(array_column(($this->jobs)($run->id), 'state'))->toBe(['running', 'cancelled']);
});

it('is a kind of job Teme can retry and cancel', function (): void {
    expect(app(JobKinds::class)->find('import'))->toBeInstanceOf(ImportJobKind::class);
});

it('stops the run when its job is cancelled, as whoever cancelled it, and tells the starter', function (): void {
    $run = ($this->start)($this->admin);

    app(CancelJob::class)->execute(new CancelJobInput(($this->jobs)($run->id)[0]['id'], (string) $this->grace->id));

    expect($run->fresh()->state)->toBe(ImportRunState::Stopped)
        ->and($run->fresh()->stop_reason)->toBe('by-hand')
        ->and(DB::table('security_events')->where('type', 'import.ended')->value('user_id'))->toBe($this->grace->id)
        ->and(($this->inbox)($this->admin))->toBe(['Cancelled: Import from old.example']);
});

it('carries a stopped run on when its job is retried, as whoever pressed Retry', function (): void {
    $run = ($this->start)($this->admin);
    ($this->stopsByItself)($run);
    $job = ($this->jobs)($run->id)[0]['id'];

    scElevated(fn () => app(RetryJob::class)->execute(new RetryJobInput($job, (string) $this->grace->id)));

    expect($run->fresh()->state)->toBe(ImportRunState::Running)
        ->and(DB::table('security_events')->where('type', 'import.started')->where('context->continued', true)->value('user_id'))->toBe($this->grace->id)
        ->and(($this->jobs)($run->id))->toHaveCount(1)
        ->and(($this->jobs)($run->id)[0])->toMatchArray(['id' => $job, 'state' => 'running', 'message' => 'Now: People']);
});

it('leaves a retried import failed, in its own words, when the password isn’t confirmed (I-1707)', function (): void {
    $run = ($this->start)($this->admin);
    ($this->stopsByItself)($run);
    $job = ($this->jobs)($run->id)[0]['id'];
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(RetryJob::class)->execute(new RetryJobInput($job, (string) $this->grace->id)))
        ->toThrow(ElevationRequiredException::class);

    expect($run->fresh()->state)->toBe(ImportRunState::Stopped)
        ->and(($this->jobs)($run->id)[0])->toMatchArray([
            'state' => 'failed',
            'message' => 'Part of this import failed three times, so it stopped. Run it again to carry on from where it stopped; the application log has the details.',
        ]);
});

it('leaves a retried import failed, in the refusal’s words, when the site has been forgotten', function (): void {
    $run = ($this->start)($this->admin);
    ($this->stopsByItself)($run);
    app(ForgetImportSource::class)->execute(new ForgetImportSourceInput($this->source->id, (string) $this->admin->id));

    expect(fn () => scElevated(fn () => app(RetryJob::class)->execute(new RetryJobInput(($this->jobs)($run->id)[0]['id'], (string) $this->admin->id))))
        ->toThrow(ValidationException::class, 'Teme has forgotten this site’s application password. Connect the site again first.');

    expect($run->fresh()->state)->toBe(ImportRunState::Stopped)
        ->and(($this->jobs)($run->id)[0])->toMatchArray(['state' => 'failed', 'message' => 'Teme has forgotten this site’s application password. Connect the site again first.']);
});

it('ends a retried job as finished, and says so, when its import has finished since', function (): void {
    $run = importRun($this->source, $this->admin);
    $old = JobsFixtures::job(['kind' => 'import', 'label' => 'Import from old.example', 'state' => 'failed', 'ended_at' => now(), 'run_type' => 'import', 'run_id' => $run->id, 'starter' => 'person', 'started_by' => $this->admin->id, 'created_at' => now()->subDay()]);

    expect(fn () => app(RetryJob::class)->execute(new RetryJobInput($old, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'This import has finished since, so there’s nothing to carry on.');

    expect(DB::table('tracked_jobs')->where('id', $old)->first(['state', 'message']))
        ->toEqual((object) ['state' => 'finished', 'message' => '3 created, 0 updated, 1 skipped, 1 failed, 4 with warnings.']);
});

it('carries nothing on, and leaves the job failed, when whoever retries it may manage jobs but not import', function (): void {
    $run = ($this->start)($this->admin);
    ($this->stopsByItself)($run);
    $editor = User::factory()->create()->assignRole('editor')->givePermissionTo('jobs.manage');
    $before = DB::table('import_runs')->orderBy('id')->get()->toJson().DB::table('security_events')->count();

    expect(fn () => scElevated(fn () => app(RetryJob::class)->execute(new RetryJobInput(($this->jobs)($run->id)[0]['id'], (string) $editor->id))))
        ->toThrow(ValidationException::class, 'Only someone who may import sites can carry this import on.');

    expect(DB::table('import_runs')->orderBy('id')->get()->toJson().DB::table('security_events')->count())->toBe($before)
        ->and(($this->jobs)($run->id)[0]['state'])->toBe('failed');
});

it('stops nothing, and reports why, when whoever cancels the job may manage jobs but not import', function (): void {
    Exceptions::fake();
    $run = ($this->start)($this->admin);
    $editor = User::factory()->create()->assignRole('editor')->givePermissionTo('jobs.manage');

    app(CancelJob::class)->execute(new CancelJobInput(($this->jobs)($run->id)[0]['id'], (string) $editor->id));

    expect($run->fresh()->state)->toBe(ImportRunState::Running)
        ->and(($this->jobs)($run->id)[0]['state'])->toBe('cancelled');

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getPrevious() instanceof AuthorizationException);
});

it('reports an import the operator started on a worker with no elevation of its own (I-392)', function (): void {
    $run = ($this->start)();
    $page = Queue::pushed(ImportListPage::class)->first();
    app()->instance(Elevation::class, new DeniedElevation);

    // The phase's end, as its batch reports it with the run's actor, which binds the operator itself.
    ($this->phaseEnds)($run, $page->actor);

    expect($page->actor->isOperator())->toBeTrue()
        ->and(($this->jobs)($run->id)[0])->toMatchArray(['done' => 1, 'message' => 'Now: Notes', 'startedBy' => ['kind' => 'operator', 'name' => null]]);
});

it('still imports when the run’s job can’t be recorded, and reports why', function (): void {
    Exceptions::fake();
    // No kind of job is installed, so the job can't start.
    (fn () => $this->tags[JobKinds::TAG] = [])->call(app());

    $run = importRun($this->source, $this->admin);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(DB::table('tracked_jobs')->count())->toBe(0);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getPrevious() instanceof ValidationException);
});

it('still imports when a step’s report is refused, and reports why', function (): void {
    Exceptions::fake();
    // A step whose label is too long for a job's message, so its report is refused.
    $long = new class implements StepPhase
    {
        public function key(): string
        {
            return 'fixture-long';
        }

        public function order(): int
        {
            return 1300;
        }

        public function label(): string
        {
            return str_repeat('A long label. ', 40);
        }

        public function runsIn(ImportRunKind $kind): bool
        {
            return true;
        }

        public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
        {
            return StepResult::done();
        }
    };
    importPhase($long);

    $run = importRun($this->source, $this->admin);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(($this->jobs)($run->id)[0]['state'])->toBe('finished');

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getPrevious() instanceof ValidationException);
});

it('still stops when the run’s job can’t be ended, and reports why', function (): void {
    Exceptions::fake();
    $run = ($this->start)($this->admin);
    // May import, but neither started the job nor manages jobs, so the job refuses them.
    $importer = User::factory()->create()->assignRole('editor')->givePermissionTo('imports.run');

    app(StopImportRun::class)->execute(new StopImportRunInput($run->id, (string) $importer->id));

    expect($run->fresh()->state)->toBe(ImportRunState::Stopped)
        ->and(($this->jobs)($run->id)[0]['state'])->toBe('running');

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getPrevious() instanceof AuthorizationException);
});
