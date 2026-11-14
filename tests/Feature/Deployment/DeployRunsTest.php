<?php

declare(strict_types=1);

use App\Domain\Deployment\DeployHistory;
use App\Domain\Deployment\DeployOutcome;
use App\Domain\Deployment\DeployReport;
use App\Domain\Deployment\Health\Doctor;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use App\Domain\Deployment\Operations\Input\RecordDeployRunInput;
use App\Domain\Deployment\Operations\RecordDeployRun;
use App\Domain\Deployment\Queries\DeployRuns;
use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Backups\ScratchSite;
use Tests\Fixtures\Deployment\DeployLockSession;
use Tests\Fixtures\Deployment\DeployRun;
use Tests\Fixtures\Deployment\StubCheck;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DeployRun::isolatePageCache();
    $this->site = ScratchSite::open();
    app()->instance(Doctor::class, new Doctor([]));
    config(['teme.version' => '0.1.0-rc.14', 'teme.revision' => str_repeat('ab', 20)]);
    $this->runs = fn () => DB::table('deploy_runs')->orderBy('finished_at')->get();
});

afterEach(function (): void {
    DeployLockSession::close();
    $this->site->close();
});

it('records a deploy that migrates: the version, the outcome, its exit code and its whole report', function (): void {
    app('migrator')->path(DeployRun::migrations('probe'));

    $run = DeployRun::here(['--json' => true]);
    $rows = ($this->runs)();

    expect($run->status)->toBe(0)
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->version)->toBe('0.1.0-rc.14')
        ->and($rows[0]->revision)->toBe(str_repeat('ab', 20))
        ->and($rows[0]->outcome)->toBe('deployed')
        ->and((int) $rows[0]->exit_code)->toBe(0)
        ->and($rows[0]->sentence)->toBe('Deployed: 1 migration ran.')
        ->and($rows[0]->backup_run_id)->toBeNull()
        ->and(json_decode((string) $rows[0]->report, true))->toEqual($run->json)
        ->and(CarbonImmutable::parse($rows[0]->started_at)->lessThanOrEqualTo(CarbonImmutable::parse($rows[0]->finished_at)))->toBeTrue();
});

it('records a deploy that ends any other way, with that outcome and exit code', function (): void {
    app()->instance(StubCheck::class, new StubCheck('Stub', [new Finding(Severity::Error, 'Something is broken.', 'Mend it.')]));
    app(Doctor::class)->register('stub', StubCheck::class);

    $run = DeployRun::here();

    expect($run->status)->toBe(2)
        ->and(($this->runs)()->pluck('outcome')->all())->toBe(['refused'])
        ->and((int) ($this->runs)()[0]->exit_code)->toBe(2);
});

it('records a failed migration with its outcome', function (): void {
    app('migrator')->path(DeployRun::migrations('failing'));

    $run = DeployRun::here();

    expect($run->status)->toBe(4)
        ->and(($this->runs)()->pluck('outcome')->all())->toBe(['migration-failed']);
});

it('records nothing for --check, which changes nothing', function (): void {
    app('migrator')->path(DeployRun::migrations('probe'));

    DeployRun::here(['--check' => true]);

    expect(($this->runs)())->toHaveCount(0);
});

it('records nothing, and keeps its outcome, on a database the release that makes the table hasn’t reached', function (): void {
    Schema::drop('deploy_runs');

    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(0)
        ->and($run->json['outcome'])->toBe('deployed')
        ->and(Schema::hasTable('deploy_runs'))->toBeFalse();
});

it('keeps the deploy’s exit code and logs the failure when its record can’t be written', function (): void {
    Exceptions::fake();
    // A column the insert doesn't fill, so the record fails as a broken table would.
    DB::statement('ALTER TABLE deploy_runs ADD COLUMN must_be_filled text NOT NULL');

    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(0)
        ->and($run->json['outcome'])->toBe('deployed')
        ->and(($this->runs)())->toHaveCount(0);

    Exceptions::assertReported(fn (QueryException $failed): bool => str_contains($failed->getMessage(), 'must_be_filled'));
});

it('records only for the operator, writing nothing when anyone else asks', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    app()->forgetInstance(Elevation::class);
    $report = DeployReport::forDeploy();

    expect(fn () => app(RecordDeployRun::class)->execute(new RecordDeployRunInput($report, CarbonImmutable::now(), CarbonImmutable::now(), (string) $admin->id)))
        ->toThrow(AuthorizationException::class);

    expect(app(DeployHistory::class)->record($report->end(DeployOutcome::Deployed, 'Deployed.'), CarbonImmutable::now()))->toBeNull();

    // Counted after the second attempt too, so a record that slipped through it would show.
    expect(($this->runs)())->toHaveCount(0);
});

it('reads the last deploys newest first, and the last that deployed', function (): void {
    app('migrator')->path(DeployRun::migrations('probe'));
    DeployRun::here();
    $this->travel(1)->minutes();
    config(['teme.version' => '0.1.0-rc.15']);
    app()->instance(StubCheck::class, new StubCheck('Stub', [new Finding(Severity::Error, 'Broken.', 'Mend it.')]));
    app(Doctor::class)->register('stub', StubCheck::class);
    DeployRun::here();

    $runs = app(DeployRuns::class);

    expect(array_column($runs->recent(), 'outcome'))->toBe(['refused', 'deployed'])
        ->and(array_column($runs->recent(), 'version'))->toBe(['0.1.0-rc.15', '0.1.0-rc.14'])
        ->and($runs->lastDeployed()['version'] ?? null)->toBe('0.1.0-rc.14');
});
