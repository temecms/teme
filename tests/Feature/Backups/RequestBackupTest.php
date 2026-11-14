<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Operations\Input\RequestBackupInput;
use App\Domain\Backups\Operations\RequestBackup;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Tracking\BackupJobKind;
use App\Domain\Jobs\JobKinds;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\BackupRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->place = BackupRecords::destination(['name' => 'This server']);
    BackupRecords::key($this->place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
    $this->request = fn (?string $actorId): string => (string) app(RequestBackup::class)->execute(new RequestBackupInput($actorId));
});

it('registers the backup as a kind of tracked job', function (): void {
    expect(app(JobKinds::class)->find('backup'))->toBeInstanceOf(BackupJobKind::class);
});

it('puts an administrator’s Back up now in line as their own waiting job, and runs nothing yet', function (): void {
    $job = TrackedJob::query()->findOrFail(($this->request)((string) $this->admin->id));

    expect($job->kind)->toBe('backup')
        ->and($job->label)->toBe('Back up now')
        ->and($job->starter)->toBe('person')
        ->and($job->started_by)->toBe($this->admin->id)
        ->and([$job->run_type, $job->run_id])->toBe(['backup', 'site'])
        ->and($job->state)->toBe(JobState::Waiting)
        ->and(BackupRun::query()->count())->toBe(0);
});

it('writes nothing when authorization fails', function (?string $role): void {
    // A request holds no operator, so no actor at all is refused as an editor is.
    $actorId = $role === null ? null : (string) User::factory()->create()->assignRole($role)->id;

    expect(fn () => ($this->request)($actorId))->toThrow(AuthorizationException::class, 'Only an administrator manages backups.')
        ->and(TrackedJob::query()->count())->toBe(0);
})->with([
    'no actor, from a request' => [null],
    'an editor' => ['editor'],
    'an author' => ['author'],
]);

it('refuses before backups are set up, and while a restore has them paused, writing nothing', function (): void {
    $this->place->keys()->where('role', BackupKeyRole::Recovery->value)->delete();

    expect(fn () => ($this->request)((string) $this->admin->id))->toThrow(ValidationException::class, 'Finish setting backups up first.');

    BackupRecords::key($this->place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
    BackupDestination::query()->update(['paused_at' => now()]);

    expect(fn () => ($this->request)((string) $this->admin->id))->toThrow(ValidationException::class, BackupPlaces::PAUSED)
        ->and(TrackedJob::query()->count())->toBe(0);
});

it('keeps one Back up now in line at a time, and takes one while a backup or a prune runs', function (): void {
    ($this->request)((string) $this->admin->id);
    $other = User::factory()->create()->assignRole('administrator');

    expect(fn () => ($this->request)((string) $other->id))->toThrow(ValidationException::class, 'A backup is already waiting to start. It starts within a minute.')
        ->and(TrackedJob::query()->count())->toBe(1);

    TrackedJob::query()->update(['state' => JobState::Finished, 'ended_at' => now()]);
    BackupRecords::run(['kind' => BackupRunKind::Prune, 'state' => BackupRunState::Running, 'started_at' => now(), 'ended_at' => null]);

    expect(TrackedJob::query()->findOrFail(($this->request)((string) $other->id))->state)->toBe(JobState::Waiting);
});
