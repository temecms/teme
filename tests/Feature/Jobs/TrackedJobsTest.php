<?php

declare(strict_types=1);

use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Jobs\Operations\Input\FailJobInput;
use App\Domain\Jobs\Operations\Input\FinishJobInput;
use App\Domain\Jobs\Operations\Input\PruneJobsInput;
use App\Domain\Jobs\Operations\Input\ReportJobProgressInput;
use App\Domain\Jobs\Operations\Input\RetryJobInput;
use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Jobs\Operations\PruneJobs;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Jobs\Operations\RetryJob;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Jobs\JobsFixtures;
use Tests\Fixtures\Jobs\RecordingJobKind;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->grace = User::factory()->create(['name' => 'Grace Editor'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Kemi Author'])->assignRole('author');
    $this->kind = RecordingJobKind::register();

    // A job Grace started: an import, 188 of 400 done, still running; $changes by column.
    $this->graces = fn (array $changes = []): string => JobsFixtures::job([...['starter' => 'person', 'started_by' => $this->grace->id], ...$changes]);

    // A person's inbox: each item's body, with its kind.
    $this->inbox = fn (User $person): array => DB::table('inbox_items')->where('user_id', $person->id)->get()
        ->map(fn (object $item): array => [...json_decode((string) $item->body, true), 'kind' => $item->kind])->all();

    $this->jobsLink = ['route' => 'tools.jobs.index', 'parameters' => [], 'ability' => 'jobs.manage'];
});

it('starts a job waiting, as the person starting it, with its run', function (): void {
    $id = app(StartJob::class)->execute(new StartJobInput(kind: 'fixture', label: 'Import from WordPress', total: 400, runType: 'import', runId: 'run-a', actorId: (string) $this->grace->id));

    expect(DB::table('tracked_jobs')->where('id', $id)->first(['kind', 'owner', 'label', 'starter', 'started_by', 'run_type', 'run_id', 'state', 'total', 'done', 'message', 'ended_at']))
        ->toEqual((object) ['kind' => 'fixture', 'owner' => 'core', 'label' => 'Import from WordPress', 'starter' => 'person', 'started_by' => $this->grace->id, 'run_type' => 'import', 'run_id' => 'run-a', 'state' => 'waiting', 'total' => 400, 'done' => 0, 'message' => null, 'ended_at' => null]);
});

it('starts the operator’s job naming no one', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);

    $id = app(StartJob::class)->execute(new StartJobInput('fixture', 'Back up now'));

    expect(TrackedJob::query()->findOrFail($id)->only(['starter', 'started_by', 'state']))
        ->toEqual(['starter' => 'operator', 'started_by' => null, 'state' => JobState::Waiting]);
});

it('starts nothing for no one, and writes nothing when authorization fails', function (string $case): void {
    $actor = match ($case) {
        'no actor, and no operator' => null,
        'an actor who isn’t a person' => '999999',
    };

    expect(fn () => app(StartJob::class)->execute(new StartJobInput('fixture', 'Import from WordPress', actorId: $actor)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('tracked_jobs')->count())->toBe(0);
})->with(['no actor, and no operator', 'an actor who isn’t a person']);

it('refuses a kind that isn’t registered, and half a run, and writes nothing', function (string $case): void {
    $input = match ($case) {
        'a kind that isn’t registered' => new StartJobInput('gone', 'Import from WordPress', actorId: (string) $this->grace->id),
        'a run’s type with no ID' => new StartJobInput('fixture', 'Import from WordPress', runType: 'import', actorId: (string) $this->grace->id),
    };

    expect(fn () => app(StartJob::class)->execute($input))->toThrow(ValidationException::class)
        ->and(DB::table('tracked_jobs')->count())->toBe(0);
})->with(['a kind that isn’t registered', 'a run’s type with no ID']);

it('moves a waiting job to running at its first report, and counts each step', function (): void {
    $id = ($this->graces)(['state' => 'waiting', 'total' => null, 'done' => 0, 'message' => null]);
    $report = fn (int $steps, ?int $total = null, ?string $message = null): bool => app(ReportJobProgress::class)->execute(new ReportJobProgressInput($id, $steps, $total, $message, (string) $this->grace->id));

    expect($report(20, 400, 'Importing posts'))->toBeTrue()
        ->and($report(30))->toBeTrue()
        ->and(DB::table('tracked_jobs')->where('id', $id)->first(['state', 'total', 'done', 'message']))
        ->toEqual((object) ['state' => 'running', 'total' => 400, 'done' => 50, 'message' => 'Importing posts']);
});

it('counts every step workers report, and never past the total', function (): void {
    $id = ($this->graces)(['total' => 400, 'done' => 380]);
    $report = fn (): bool => app(ReportJobProgress::class)->execute(new ReportJobProgressInput($id, 15, actorId: (string) $this->grace->id));

    expect($report())->toBeTrue()
        ->and(DB::table('tracked_jobs')->where('id', $id)->value('done'))->toBe(395)
        ->and($report())->toBeTrue()
        ->and(DB::table('tracked_jobs')->where('id', $id)->value('done'))->toBe(400);
});

it('ignores a report on a job that has ended, and says so, so the work stops', function (): void {
    $id = ($this->graces)(['state' => 'cancelled', 'ended_at' => now(), 'message' => 'Cancelled by Ada Admin.']);

    expect(app(ReportJobProgress::class)->execute(new ReportJobProgressInput($id, 20, message: 'Importing posts', actorId: (string) $this->grace->id)))->toBeFalse()
        ->and(DB::table('tracked_jobs')->where('id', $id)->first(['state', 'done', 'message']))
        ->toEqual((object) ['state' => 'cancelled', 'done' => 188, 'message' => 'Cancelled by Ada Admin.']);
});

it('refuses a report from someone whose business the job isn’t, and writes nothing', function (): void {
    $id = ($this->graces)();

    expect(fn () => app(ReportJobProgress::class)->execute(new ReportJobProgressInput($id, 20, actorId: (string) $this->author->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('tracked_jobs')->where('id', $id)->value('done'))->toBe(188);
});

it('takes reports from an administrator the work carried on as', function (): void {
    $id = ($this->graces)();

    expect(app(ReportJobProgress::class)->execute(new ReportJobProgressInput($id, 12, actorId: (string) $this->admin->id)))->toBeTrue()
        ->and(DB::table('tracked_jobs')->where('id', $id)->value('done'))->toBe(200);
});

it('finishes a job and tells whoever started it in their inbox (I-221)', function (): void {
    $id = ($this->graces)();

    expect(app(FinishJob::class)->execute(new FinishJobInput($id, '400 posts came in.', (string) $this->grace->id)))->toBeTrue()
        ->and(DB::table('tracked_jobs')->where('id', $id)->first(['state', 'done', 'message']))
        ->toEqual((object) ['state' => 'finished', 'done' => 400, 'message' => '400 posts came in.'])
        ->and(DB::table('tracked_jobs')->where('id', $id)->value('ended_at'))->not->toBeNull()
        ->and(($this->inbox)($this->grace))->toEqual([[
            'title' => 'Finished: Import from WordPress',
            'text' => '400 posts came in.',
            'link' => $this->jobsLink,
            'kind' => 'job.finished',
        ]]);
});

it('fails a job, says what to do next, and tells whoever started it (I-1714)', function (): void {
    $id = ($this->graces)();

    expect(app(FailJob::class)->execute(new FailJobInput($id, 'The old site stopped answering.', (string) $this->grace->id)))->toBeTrue()
        ->and(DB::table('tracked_jobs')->where('id', $id)->first(['state', 'done', 'message']))
        ->toEqual((object) ['state' => 'failed', 'done' => 188, 'message' => 'The old site stopped answering.'])
        ->and(($this->inbox)($this->grace))->toEqual([[
            'title' => 'Failed: Import from WordPress',
            'text' => 'The old site stopped answering. Retrying carries on from where it stopped.',
            'link' => $this->jobsLink,
            'kind' => 'job.failed',
        ]]);
});

it('tells no one’s inbox when a job the operator started ends', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $id = JobsFixtures::job();

    expect(app(FinishJob::class)->execute(new FinishJobInput($id, 'Backed up 38 MB.')))->toBeTrue()
        ->and(DB::table('inbox_items')->count())->toBe(0);
});

it('leaves an ended job as it ended when a finish or a failure comes late', function (): void {
    $id = ($this->graces)(['state' => 'cancelled', 'ended_at' => now(), 'message' => 'Cancelled by Ada Admin.']);

    expect(app(FinishJob::class)->execute(new FinishJobInput($id, '400 posts came in.', (string) $this->grace->id)))->toBeFalse()
        ->and(app(FailJob::class)->execute(new FailJobInput($id, 'The old site stopped answering.', (string) $this->grace->id)))->toBeFalse()
        ->and(DB::table('tracked_jobs')->where('id', $id)->value('state'))->toBe('cancelled')
        ->and(DB::table('inbox_items')->count())->toBe(0);
});

it('refuses to finish or fail a job whose business it isn’t, and writes nothing', function (string $action): void {
    $id = ($this->graces)();
    $run = $action === 'finish'
        ? fn () => app(FinishJob::class)->execute(new FinishJobInput($id, '400 posts came in.', (string) $this->author->id))
        : fn () => app(FailJob::class)->execute(new FailJobInput($id, 'The old site stopped answering.', (string) $this->author->id));

    expect($run)->toThrow(AuthorizationException::class)
        ->and(DB::table('tracked_jobs')->where('id', $id)->value('state'))->toBe('running')
        ->and(DB::table('inbox_items')->count())->toBe(0);
})->with(['finish', 'fail']);

it('retries a failed job once, however often Retry is pressed (I-1714)', function (): void {
    $id = ($this->graces)(['state' => 'failed', 'ended_at' => now(), 'message' => 'The old site stopped answering.']);
    $retry = fn () => app(RetryJob::class)->execute(new RetryJobInput($id, (string) $this->admin->id));

    $record = $retry();

    expect($record->state)->toBe(JobState::Waiting)
        ->and($record->label)->toBe('Import from WordPress')
        ->and(DB::table('tracked_jobs')->where('id', $id)->first(['state', 'done', 'message', 'ended_at']))
        ->toEqual((object) ['state' => 'waiting', 'done' => 188, 'message' => 'Retrying from where it stopped.', 'ended_at' => null])
        ->and($this->kind->retried)->toBe([['job' => $id, 'by' => (string) $this->admin->id]])
        ->and($retry)->toThrow(ValidationException::class, 'This job isn’t failed, so there’s nothing to retry.')
        ->and($this->kind->retried)->toHaveCount(1);
});

it('refuses to retry a job whose kind is no longer installed, and changes nothing', function (): void {
    $id = ($this->graces)(['kind' => 'gone', 'state' => 'failed', 'ended_at' => now()]);

    expect(fn () => app(RetryJob::class)->execute(new RetryJobInput($id, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'Teme can’t retry this job, because what runs it is no longer installed.')
        ->and(DB::table('tracked_jobs')->where('id', $id)->value('state'))->toBe('failed');
});

it('refuses to retry or cancel someone else’s job without jobs.manage, and writes nothing', function (string $action): void {
    $retrying = $action === 'retry';
    $id = ($this->graces)($retrying ? ['state' => 'failed', 'ended_at' => now()] : []);
    $run = $retrying
        ? fn () => app(RetryJob::class)->execute(new RetryJobInput($id, (string) $this->author->id))
        : fn () => app(CancelJob::class)->execute(new CancelJobInput($id, (string) $this->author->id));

    expect($run)->toThrow(AuthorizationException::class)
        ->and(DB::table('tracked_jobs')->where('id', $id)->value('state'))->toBe($retrying ? 'failed' : 'running')
        ->and([...$this->kind->retried, ...$this->kind->cancelled])->toBe([]);
})->with(['retry', 'cancel']);

it('cancels a running job, tells its starter when someone else did, and asks the kind to stop', function (): void {
    $id = ($this->graces)();

    $record = app(CancelJob::class)->execute(new CancelJobInput($id, (string) $this->admin->id));

    expect($record->state)->toBe(JobState::Cancelled)
        ->and(DB::table('tracked_jobs')->where('id', $id)->first(['state', 'done', 'message']))
        ->toEqual((object) ['state' => 'cancelled', 'done' => 188, 'message' => 'Cancelled by Ada Admin.'])
        ->and(($this->inbox)($this->grace))->toEqual([[
            'title' => 'Cancelled: Import from WordPress',
            'text' => 'Ada Admin cancelled it. What it did stays done.',
            'link' => $this->jobsLink,
            'kind' => 'job.cancelled',
        ]])
        ->and($this->kind->cancelled)->toBe([['job' => $id, 'by' => (string) $this->admin->id]]);
});

it('cancels one’s own job without telling oneself', function (): void {
    $id = ($this->graces)(['state' => 'waiting', 'done' => 0]);

    app(CancelJob::class)->execute(new CancelJobInput($id, (string) $this->grace->id));

    expect(DB::table('tracked_jobs')->where('id', $id)->value('message'))->toBe('Cancelled by Grace Editor.')
        ->and(DB::table('inbox_items')->count())->toBe(0);
});

it('refuses to cancel a job that has already ended', function (): void {
    $id = ($this->graces)(['state' => 'finished', 'done' => 400, 'ended_at' => now(), 'message' => '400 posts came in.']);

    expect(fn () => app(CancelJob::class)->execute(new CancelJobInput($id, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'This job has already ended, so there’s nothing to cancel.')
        ->and(DB::table('tracked_jobs')->where('id', $id)->value('state'))->toBe('finished')
        ->and($this->kind->cancelled)->toBe([]);
});

it('deletes jobs 90 days after they ended, and never one under way', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00', 'UTC'));
    JobsFixtures::job(['state' => 'finished', 'done' => 400, 'ended_at' => now()->subDays(91), 'created_at' => now()->subDays(92)]);
    JobsFixtures::job(['state' => 'failed', 'ended_at' => now()->subDays(91), 'created_at' => now()->subDays(92)]);
    $recent = JobsFixtures::job(['state' => 'cancelled', 'ended_at' => now()->subDays(89), 'created_at' => now()->subDays(92)]);
    $running = JobsFixtures::job(['created_at' => now()->subDays(200)]);

    $this->artisan('teme:jobs:prune')->expectsOutput('Deleted 2 ended jobs.')->assertSuccessful();

    expect(DB::table('tracked_jobs')->pluck('id')->sort()->values()->all())->toBe(collect([$recent, $running])->sort()->values()->all());
});

it('prunes jobs only as the operator, and writes nothing otherwise', function (): void {
    JobsFixtures::job(['state' => 'finished', 'done' => 400, 'ended_at' => now()->subDays(91)]);

    expect(fn () => app(PruneJobs::class)->execute(new PruneJobsInput(CarbonImmutable::now()->subDays(90), 1000, (string) $this->admin->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('tracked_jobs')->count())->toBe(1);
});

it('puts the prune on the schedule', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('teme:jobs:prune')->assertSuccessful();
});
