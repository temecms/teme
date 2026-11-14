<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\Operations\Input\RequestBackupInput;
use App\Domain\Backups\Operations\RequestBackup;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\ScreenRecords;
use Tests\Fixtures\Jobs\JobsFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->place = ScreenRecords::place('This server');
    $this->screen = fn () => $this->actingAs($this->admin)->get(route('tools.backups.index'));
    $this->request = fn (): string => (string) app(RequestBackup::class)->execute(new RequestBackupInput((string) $this->admin->id));
});

it('puts Back up now in line for an administrator, as their own job, and says when it starts', function (): void {
    $this->actingAs($this->admin)->post(route('tools.backups.now'))
        ->assertRedirect(route('tools.backups.index'))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Backup requested. It starts within a minute.');

    expect(TrackedJob::query()->sole()->started_by)->toBe($this->admin->id);
});

it('refuses Back up now in an error toast, in the Operation’s words, writing nothing more', function (): void {
    ($this->request)();

    $this->actingAs($this->admin)->post(route('tools.backups.now'))
        ->assertRedirect(route('tools.backups.index'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'A backup is already waiting to start. It starts within a minute.');

    expect(TrackedJob::query()->count())->toBe(1);
});

it('answers 403 to anyone without backups.manage, writing nothing', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);

    $this->actingAs($person)->post(route('tools.backups.now'))->assertForbidden();

    expect(TrackedJob::query()->count())->toBe(0);
})->with(['editor', 'author', 'contributor']);

it('gives the screen the backup job as Tools › Jobs draws it, and what Back up now may do', function (): void {
    ($this->screen)()->assertInertia(fn (Assert $page) => $page
        ->where('backupJob', null)
        ->where('backUpNow', 'offered'));

    $id = ($this->request)();

    ($this->screen)()->assertInertia(fn (Assert $page) => $page
        ->where('backupJob.id', $id)
        ->where('backupJob.label', 'Back up now')
        ->where('backupJob.state', 'waiting')
        ->where('backupJob.startedBy.name', 'Ada Admin')
        ->where('backupJob.canCancel', true)
        ->where('backUpNow', 'busy'));
});

it('shows a retried older job, open again, rather than a newer one that has ended', function (): void {
    $backupJob = fn (array $changes): string => JobsFixtures::job([
        'kind' => 'backup', 'label' => 'Back up now', 'starter' => 'person', 'started_by' => $this->admin->id,
        'run_type' => 'backup', 'run_id' => 'site', 'total' => null, 'done' => 0, 'message' => null, ...$changes,
    ]);
    // Retry sets an old job waiting again and leaves its created_at alone.
    $retried = $backupJob(['state' => 'waiting', 'created_at' => now()->subDay(), 'ended_at' => null]);
    $backupJob(['state' => 'finished', 'total' => 7, 'done' => 7, 'message' => 'Done.', 'created_at' => now()->subHour(), 'ended_at' => now()->subMinutes(50)]);

    ($this->screen)()->assertInertia(fn (Assert $page) => $page
        ->where('backupJob.id', $retried)
        ->where('backupJob.state', 'waiting')
        ->where('backUpNow', 'busy'));
});

it('hides Back up now until backups are set up, and holds it while paused or while a backup or prune runs', function (): void {
    $this->place->keys()->where('role', BackupKeyRole::Recovery->value)->delete();
    ($this->screen)()->assertInertia(fn (Assert $page) => $page->where('setup.state', 'unfinished')->where('backUpNow', 'hidden'));

    BackupRecords::key($this->place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
    $this->place->forceFill(['paused_at' => now()])->save();
    ($this->screen)()->assertInertia(fn (Assert $page) => $page->where('backUpNow', 'paused'));

    $this->place->forceFill(['paused_at' => null])->save();
    BackupRecords::run(['kind' => BackupRunKind::Prune, 'state' => BackupRunState::Running, 'started_at' => now(), 'ended_at' => null]);
    ($this->screen)()->assertInertia(fn (Assert $page) => $page->where('backUpNow', 'busy'));
});

it('tells a site with no backup yet that Back up now makes one', function (): void {
    ($this->screen)()->assertInertia(fn (Assert $page) => $page
        ->where('verdict.level', 'amber')
        ->where('firstBackup', 'Or press Back up now to make one within a minute.'));
});

it('answers a poll with the job, the running line and Back up now alone', function (): void {
    $id = ($this->request)();

    ($this->screen)()->assertInertia(fn (Assert $page) => $page
        ->reloadOnly(['backupJob', 'running', 'backUpNow'], fn (Assert $reload) => $reload
            ->where('backupJob.id', $id)
            ->where('backupJob.state', 'waiting')
            ->where('running', null)
            ->missing('verdict')
            ->missing('places')
            ->missing('checks')));
});

it('cancels a waiting Back up now from the screen, back to the screen', function (): void {
    $id = ($this->request)();

    $this->actingAs($this->admin)->from(route('tools.backups.index'))->post(route('tools.jobs.cancel', $id))
        ->assertRedirect(route('tools.backups.index'))
        ->assertInertiaFlash('toast.message', 'Cancelled Back up now. What it did stays done.');

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Cancelled);
});
