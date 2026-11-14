<?php

declare(strict_types=1);

use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Updates\Operations\BeginTemeUpdate;
use App\Domain\Updates\Operations\EndTemeUpdate;
use App\Domain\Updates\Operations\Input\BeginTemeUpdateInput;
use App\Domain\Updates\Operations\Input\EndTemeUpdateInput;
use App\Domain\Updates\Operations\Input\RecordTemeUpdateStepInput;
use App\Domain\Updates\Operations\RecordTemeUpdateStep;
use App\Domain\Updates\Requests\UpdateStatus;
use App\Domain\Updates\Requests\UpdateStep;
use App\Models\User;
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
    Queue::fake();
    updOperator();
    $this->row = fn (string $status): string => tap(strtolower((string) Str::ulid()), fn (string $id) => DB::table('teme_updates')->insert([
        'id' => $id, 'kind' => 'update', 'from_version' => '0.1.0-rc.14', 'to_version' => '0.1.0-rc.15', 'status' => $status,
        'steps' => '[]', 'requested_at' => now(),
    ]));
    $this->steps = fn (string $id): array => json_decode((string) DB::table('teme_updates')->where('id', $id)->value('steps'), true);
});

it('begins a request that waits, with its seven steps waiting', function (): void {
    $id = ($this->row)('requested');

    $began = app(BeginTemeUpdate::class)->execute(new BeginTemeUpdateInput($id));
    $row = DB::table('teme_updates')->where('id', $id)->first();

    expect($began)->toBeTrue()
        ->and($row->status)->toBe('running')
        ->and($row->started_at)->not->toBeNull()
        ->and($row->step)->toBe('deploy')
        ->and(($this->steps)($id))->toEqual(array_map(fn (UpdateStep $step): array => ['step' => $step->value, 'state' => 'pending', 'startedAt' => null, 'finishedAt' => null, 'detail' => null, 'asks' => 0, 'runsAfter' => null, 'mark' => null], UpdateStep::cases()))
        ->and(array_column(($this->steps)($id), 'step'))->toBe(['deploy', 'web', 'worker', 'worker-long', 'scheduler', 'check', 'updater'])
        ->and(json_encode($row))->not->toContain('ghcr.io');
});

it('begins nothing that was cancelled first, and says so', function (): void {
    $id = ($this->row)('cancelled');

    expect(app(BeginTemeUpdate::class)->execute(new BeginTemeUpdateInput($id)))->toBeFalse()
        ->and(DB::table('teme_updates')->where('id', $id)->value('status'))->toBe('cancelled');
});

it('records a step’s state, detail, backup and deploy run on a running request', function (): void {
    $id = ($this->row)('requested');
    app(BeginTemeUpdate::class)->execute(new BeginTemeUpdateInput($id));
    $record = fn (UpdateStep $step, string $state, ?string $detail = null, ?string $backup = null, ?string $deploy = null) => app(RecordTemeUpdateStep::class)->execute(new RecordTemeUpdateStepInput($id, $step, $state, $detail, $backup, $deploy));

    $record(UpdateStep::Web, 'started', '1 of 3 answers with Teme 0.1.0-rc.15');
    $started = collect(($this->steps)($id))->firstWhere('step', 'web');
    $record(UpdateStep::Web, 'done', null, '01backup', '01deploy');
    $done = collect(($this->steps)($id))->firstWhere('step', 'web');
    $row = DB::table('teme_updates')->where('id', $id)->first();

    expect($started['state'])->toBe('started')
        ->and($started['startedAt'])->not->toBeNull()
        ->and($started['finishedAt'])->toBeNull()
        ->and($started['detail'])->toBe('1 of 3 answers with Teme 0.1.0-rc.15')
        ->and($done['state'])->toBe('done')
        ->and($done['startedAt'])->toBe($started['startedAt'])
        ->and($done['finishedAt'])->not->toBeNull()
        ->and($row->step)->toBe('web')
        ->and($row->backup_run_id)->toBe('01backup')
        ->and($row->deploy_run_id)->toBe('01deploy');
});

it('records a failed or switched back step with its finish', function (string $state): void {
    $id = ($this->row)('requested');
    app(BeginTemeUpdate::class)->execute(new BeginTemeUpdateInput($id));

    app(RecordTemeUpdateStep::class)->execute(new RecordTemeUpdateStepInput($id, UpdateStep::Check, $state, 'A sentence.'));
    $check = collect(($this->steps)($id))->firstWhere('step', 'check');

    expect($check['state'])->toBe($state)->and($check['finishedAt'])->not->toBeNull();
})->with(['failed', 'switched-back']);

it('refuses a state a step isn’t recorded in', function (): void {
    $id = ($this->row)('requested');
    app(BeginTemeUpdate::class)->execute(new BeginTemeUpdateInput($id));

    expect(fn () => app(RecordTemeUpdateStep::class)->execute(new RecordTemeUpdateStepInput($id, UpdateStep::Web, 'halfway')))
        ->toThrow(ValidationException::class);
});

it('refuses to record a step on a request that isn’t running, writing nothing', function (string $status): void {
    $id = ($this->row)($status);
    $before = json_encode(DB::table('teme_updates')->where('id', $id)->first());

    expect(fn () => app(RecordTemeUpdateStep::class)->execute(new RecordTemeUpdateStepInput($id, UpdateStep::Web, 'started')))
        ->toThrow(ValidationException::class);
    expect(json_encode(DB::table('teme_updates')->where('id', $id)->first()))->toBe($before);
})->with(['requested', 'done', 'cancelled']);

it('ends a request done, switched back or failed, with its sentence', function (UpdateStatus $status): void {
    $id = ($this->row)('requested');
    app(BeginTemeUpdate::class)->execute(new BeginTemeUpdateInput($id));

    app(EndTemeUpdate::class)->execute(new EndTemeUpdateInput($id, $status, 'A sentence.'));
    $row = DB::table('teme_updates')->where('id', $id)->first();

    expect($row->status)->toBe($status->value)->and($row->error)->toBe('A sentence.')->and($row->finished_at)->not->toBeNull();
})->with([UpdateStatus::Done, UpdateStatus::SwitchedBack, UpdateStatus::Failed]);

it('ends a request that waits as failed when the updater refuses it before beginning', function (): void {
    $id = ($this->row)('requested');

    app(EndTemeUpdate::class)->execute(new EndTemeUpdateInput($id, UpdateStatus::Failed, 'GitHub no longer lists Teme 0.1.0-rc.15, so nothing was changed.'));

    expect(DB::table('teme_updates')->where('id', $id)->value('status'))->toBe('failed');
});

it('refuses to end a request that is over, or to end one as anything but done, switched back or failed', function (): void {
    $over = ($this->row)('done');
    $open = ($this->row)('requested');

    expect(fn () => app(EndTemeUpdate::class)->execute(new EndTemeUpdateInput($over, UpdateStatus::Failed, 'x')))->toThrow(ValidationException::class);
    expect(fn () => app(EndTemeUpdate::class)->execute(new EndTemeUpdateInput($open, UpdateStatus::Cancelled, 'x')))->toThrow(ValidationException::class);
    expect(DB::table('teme_updates')->where('id', $open)->value('status'))->toBe('requested');
});

it('writes nothing when anyone but the operator asks', function (string $who): void {
    $id = ($this->row)('requested');
    $running = ($this->row)('done');
    $admin = User::factory()->create()->assignRole('administrator');
    app()->instance(Elevation::class, new OperatorElevation);
    $actor = $who === 'an administrator' ? (string) $admin->id : null;

    if ($who === 'nobody') {
        app()->forgetInstance(Elevation::class);
    }

    $before = json_encode(DB::table('teme_updates')->orderBy('id')->get());

    expect(fn () => app(BeginTemeUpdate::class)->execute(new BeginTemeUpdateInput($id, $actor)))->toThrow(AuthorizationException::class);
    expect(fn () => app(RecordTemeUpdateStep::class)->execute(new RecordTemeUpdateStepInput($id, UpdateStep::Web, 'started', actorId: $actor)))->toThrow(AuthorizationException::class);
    expect(fn () => app(EndTemeUpdate::class)->execute(new EndTemeUpdateInput($id, UpdateStatus::Failed, 'x', $actor)))->toThrow(AuthorizationException::class);
    expect(json_encode(DB::table('teme_updates')->orderBy('id')->get()))->toBe($before)->and($running)->not->toBe($id);
})->with(['an administrator', 'nobody']);

it('keeps a step’s start when it is recorded started again, and starts it again after the step was done', function (): void {
    $id = ($this->row)('requested');
    app(BeginTemeUpdate::class)->execute(new BeginTemeUpdateInput($id));
    $record = fn (string $state, ?string $detail = null) => app(RecordTemeUpdateStep::class)->execute(new RecordTemeUpdateStepInput($id, UpdateStep::Web, $state, $detail));
    $web = fn (): array => collect(($this->steps)($id))->firstWhere('step', 'web');

    $record('started');
    $first = $web()['startedAt'];
    $this->travel(5)->seconds();
    $record('started', '1 of 3 answers with Teme 0.1.0-rc.15');
    $kept = $web()['startedAt'];
    $record('done');
    $this->travel(5)->seconds();
    $record('started');

    expect($kept)->toBe($first)
        ->and($web()['startedAt'])->not->toBe($first)
        ->and($web()['finishedAt'])->toBeNull();
});

it('counts how many times a step asked the platform, keeping the count when none is given', function (): void {
    $id = ($this->row)('requested');
    app(BeginTemeUpdate::class)->execute(new BeginTemeUpdateInput($id));
    $record = fn (string $state, ?int $asks = null) => app(RecordTemeUpdateStep::class)->execute(new RecordTemeUpdateStepInput($id, UpdateStep::Web, $state, null, null, null, $asks));
    $web = fn (): array => collect(($this->steps)($id))->firstWhere('step', 'web');

    expect($web()['asks'])->toBe(0);

    $record('started', 1);
    $record('started');

    expect($web()['asks'])->toBe(1);

    $record('started', 2);
    $record('done');

    expect($web()['asks'])->toBe(2);
});

it('keeps the moment after which a deploy run counts, when none is given', function (): void {
    $id = ($this->row)('requested');
    app(BeginTemeUpdate::class)->execute(new BeginTemeUpdateInput($id));
    $record = fn (string $state, ?string $runsAfter = null) => app(RecordTemeUpdateStep::class)->execute(new RecordTemeUpdateStepInput($id, UpdateStep::Deploy, $state, runsAfter: $runsAfter));
    $deploy = fn (): array => collect(($this->steps)($id))->firstWhere('step', 'deploy');

    $record('started', '2027-03-02T10:00:00+00:00');
    $record('started', null);
    $record('done');

    expect($deploy()['runsAfter'])->toBe('2027-03-02T10:00:00+00:00');
});

it('keeps the deployment a step saw before asking, when none is given', function (): void {
    $id = ($this->row)('requested');
    app(BeginTemeUpdate::class)->execute(new BeginTemeUpdateInput($id));
    $record = fn (string $state, ?string $mark = null) => app(RecordTemeUpdateStep::class)->execute(new RecordTemeUpdateStepInput($id, UpdateStep::Web, $state, mark: $mark));
    $web = fn (): array => collect(($this->steps)($id))->firstWhere('step', 'web');

    $record('started', 'web-3');
    $record('started');
    $record('done');

    expect($web()['mark'])->toBe('web-3');
});
