<?php

declare(strict_types=1);

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\PruneBackups;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\RunnableBackups;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->backups = RunnableBackups::atFolders(2);
    // One backup a day, nothing weekly or monthly: of two backups today, the newer stays.
    SettingsGroupRecord::query()->whereKey(BackupSettings::GROUP)->update(['overrides' => json_encode(['keep_daily' => 1, 'keep_weekly' => 0, 'keep_monthly' => 0])]);
    Cache::memo()->forget(SettingsRepository::cacheKey(BackupSettings::GROUP));
});

afterEach(function (): void {
    $this->backups->delete();
    OtherSession::close();
});

it('forgets every backup the settings don’t keep, and unfinished ones, then prunes and checks each place', function (): void {
    $older = app(BackupSite::class)->run(BackupTrigger::Command);
    $newer = app(BackupSite::class)->run(BackupTrigger::Command);
    $first = $this->backups->repositories[0]->repository;
    // An unfinished run, and a snapshot someone made with restic by hand.
    app(Restic::class)->run($first, ['backup', ...BackupSnapshots::partFlags('01junfinished0000000000000', BackupSnapshots::DATABASE), '--stdin', '--stdin-filename', 'teme.dump'], input: 'half');
    app(Restic::class)->run($first, ['backup', '--tag', 'their-own', '--stdin', '--stdin-filename', 'notes.txt'], input: 'mine');

    $result = app(PruneBackups::class)->run(BackupTrigger::Schedule);
    $steps = BackupRun::query()->where('parent_id', $result->runId)->get();
    $prunes = $steps->where('kind', BackupRunKind::Prune)->keyBy('destination_id');

    expect($older->allWell() && $newer->allWell())->toBeTrue()
        ->and($result->succeeded)->toBeTrue((string) $result->sentence)
        ->and(array_map(fn ($run): string => $run->runId, $this->backups->runsAt(0)))->toBe([$newer->runId])
        ->and(array_map(fn ($run): string => $run->runId, $this->backups->runsAt(1)))->toBe([$newer->runId])
        ->and(app(Restic::class)->run($first, ['snapshots', '--tag', 'their-own'])->json())->toHaveCount(1)
        ->and($prunes[$this->backups->places[0]->id]->figures)->toEqual(['seconds' => $prunes[$this->backups->places[0]->id]->figures['seconds'], 'runs_kept' => 1, 'runs_forgotten' => 2, 'snapshots_forgotten' => 5])
        ->and($prunes[$this->backups->places[1]->id]->figures['runs_forgotten'])->toBe(1)
        ->and($steps->where('kind', BackupRunKind::Check)->where('state', BackupRunState::Finished)->count())->toBe(2)
        ->and(BackupRun::query()->findOrFail($result->runId)->started_by)->toBe(BackupTrigger::Schedule);
});

it('records a place that fails, in words, and prunes the others', function (): void {
    app(BackupSite::class)->run(BackupTrigger::Command);
    $this->backups->repositories[0]->delete();

    $result = app(PruneBackups::class)->run(BackupTrigger::Schedule);
    $prunes = BackupRun::query()->where('parent_id', $result->runId)->where('kind', BackupRunKind::Prune->value)->get()->keyBy('destination_id');

    expect($result->succeeded)->toBeFalse()
        ->and($result->sentence)->toBe('Teme found no backups at Place 1. Check its address, and that the storage is still there.')
        ->and($prunes[$this->backups->places[0]->id]->state)->toBe(BackupRunState::Failed)
        ->and($prunes[$this->backups->places[1]->id]->state)->toBe(BackupRunState::Finished)
        ->and(BackupRun::query()->findOrFail($result->runId)->state)->toBe(BackupRunState::Failed);
});

it('refuses while a backup runs, and writes nothing when authorization fails', function (): void {
    OtherSession::holdBackupLock();

    expect(fn () => app(PruneBackups::class)->run(BackupTrigger::Schedule))
        ->toThrow(BackupAlreadyRunning::class, 'A backup is already running. Try again when it has finished.');

    OtherSession::close();
    app()->forgetInstance(Elevation::class);
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => app(PruneBackups::class)->run(BackupTrigger::Person, (string) $editor->id))
        ->toThrow(AuthorizationException::class, 'Only an administrator manages backups.')
        ->and(BackupRun::query()->count())->toBe(0);
});
