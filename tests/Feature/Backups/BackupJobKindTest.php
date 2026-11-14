<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\Input\RetryJobInput;
use App\Domain\Jobs\Operations\RetryJob;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Jobs\JobsFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->place = BackupRecords::destination(['name' => 'This server']);
    BackupRecords::key($this->place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
    $this->backupJob = fn (array $changes = []): string => JobsFixtures::job([
        'kind' => 'backup',
        'label' => 'Back up now',
        'starter' => 'person',
        'started_by' => $this->admin->id,
        'run_type' => 'backup',
        'run_id' => 'site',
        'state' => 'failed',
        'total' => 7,
        'done' => 3,
        'message' => 'No place answered, so nothing was backed up.',
        'ended_at' => now(),
        ...$changes,
    ]);
    $this->retry = fn (string $id) => app(RetryJob::class)->execute(new RetryJobInput($id, (string) $this->admin->id));
});

it('leaves a retried backup waiting for the scheduler, starting nothing itself', function (): void {
    $id = ($this->backupJob)();

    ($this->retry)($id);
    $job = TrackedJob::query()->findOrFail($id);

    expect($job->state)->toBe(JobState::Waiting)
        ->and($job->message)->toBe('Retrying from where it stopped.')
        ->and(BackupRun::query()->count())->toBe(0);
});

it('fails a retried backup again, in words, while backups are paused or not set up', function (): void {
    $id = ($this->backupJob)();
    BackupDestination::query()->update(['paused_at' => now()]);

    expect(fn () => ($this->retry)($id))->toThrow(ValidationException::class, BackupPlaces::PAUSED)
        ->and(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Failed)
        ->and(TrackedJob::query()->findOrFail($id)->message)->toBe(BackupPlaces::PAUSED);

    BackupDestination::query()->update(['paused_at' => null]);
    $this->place->keys()->where('role', BackupKeyRole::Recovery->value)->delete();

    expect(fn () => ($this->retry)($id))->toThrow(ValidationException::class, 'Finish setting backups up first.')
        ->and(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Failed);
});

it('fails a retried backup again when another already waits, so retrying many never piles backups up', function (): void {
    $waiting = ($this->backupJob)(['state' => 'waiting', 'ended_at' => null, 'message' => null, 'done' => 0, 'total' => null]);
    $id = ($this->backupJob)();

    expect(fn () => ($this->retry)($id))->toThrow(ValidationException::class, 'A backup is already waiting to start, so this one wasn’t retried.')
        ->and(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Failed)
        ->and(TrackedJob::query()->findOrFail($waiting)->state)->toBe(JobState::Waiting);
});

it('refuses a retry from someone who looks after jobs but may not back up, leaving the nightly job failed and starting nothing', function (): void {
    $keeper = User::factory()->create()->assignRole('editor');
    $keeper->givePermissionTo('jobs.manage');
    $nightly = ($this->backupJob)(['label' => 'Nightly backup', 'starter' => 'operator', 'started_by' => null]);

    expect(fn () => app(RetryJob::class)->execute(new RetryJobInput($nightly, (string) $keeper->id)))
        ->toThrow(ValidationException::class, 'Only an administrator manages backups.')
        ->and(TrackedJob::query()->findOrFail($nightly)->state)->toBe(JobState::Failed)
        ->and(TrackedJob::query()->where('state', JobState::Waiting->value)->exists())->toBeFalse()
        ->and(BackupRun::query()->count())->toBe(0);
});

it('fails a person’s retried backup again when their account is gone', function (): void {
    $id = ($this->backupJob)(['started_by' => null]);

    expect(fn () => ($this->retry)($id))->toThrow(ValidationException::class, 'The person who asked for this backup no longer has an account, so it can’t run again. Press Back up now instead.')
        ->and(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Failed);
});
