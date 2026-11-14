<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupKey;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Operations\AddRecoveryKey;
use App\Domain\Backups\Operations\CheckRecoveryKey;
use App\Domain\Backups\Operations\ConfirmRestoredSite;
use App\Domain\Backups\Operations\Input\AddRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\CheckRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\ConfirmRestoredSiteInput;
use App\Domain\Backups\Operations\Input\StartBackupRunInput;
use App\Domain\Backups\Operations\StartBackupRun;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\Queries\DueBackupWork;
use App\Domain\Backups\RecoveryKey;
use App\Domain\Backups\RestoredSiteAnswer;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\RunnableBackups;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->backups = null;
    // Two places as a restore leaves them: set up, and paused.
    $this->pause = function (): array {
        $first = BackupRecords::destination(['name' => 'This server', 'paused_at' => now(), 'created_at' => now()->subMinutes(2)]);
        $second = BackupRecords::destination(['name' => 'Offsite', 'kind' => 's3', 'paused_at' => now(), 'created_at' => now()->subMinute()]);
        BackupRecords::key($first);
        BackupRecords::key($first, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
        BackupRecords::key($second);

        return [$first, $second];
    };
});

afterEach(function (): void {
    $this->backups?->delete();
    OtherSession::close();
});

it('gives every place a pause, empty until a restore sets it', function (): void {
    $place = BackupRecords::destination();

    expect(Schema::hasColumn('backup_destinations', 'paused_at'))->toBeTrue()
        ->and($place->fresh()->paused_at)->toBeNull()
        ->and(app(BackupPlaces::class)->isPaused())->toBeFalse();
});

it('leaves paused places out of the places a backup uses, and says backups are paused', function (): void {
    [$first] = ($this->pause)();
    $removed = BackupRecords::destination(['state' => BackupDestinationState::Removed, 'removed_at' => now(), 'paused_at' => now()]);

    expect(app(BackupPlaces::class)->inUse())->toHaveCount(0)
        ->and(app(BackupPlaces::class)->isPaused())->toBeTrue()
        ->and(app(BackupSetup::class)->placesInUse()->pluck('id')->all())->toContain($first->id)
        ->and(app(BackupSetup::class)->placesInUse()->pluck('id')->all())->not->toContain($removed->id);

    BackupDestination::query()->whereKey($first->id)->update(['paused_at' => null]);
    BackupDestination::query()->where('name', 'Offsite')->update(['paused_at' => null]);

    // A removed place's old pause counts for nothing.
    expect(app(BackupPlaces::class)->isPaused())->toBeFalse()
        ->and(app(BackupPlaces::class)->inUse())->toHaveCount(2);
});

it('refuses a whole backup or prune while paused, writing nothing, and still records a step', function (): void {
    ($this->pause)();
    $start = app(StartBackupRun::class);

    foreach ([BackupRunKind::Backup, BackupRunKind::Prune] as $kind) {
        expect(fn () => $start->execute(new StartBackupRunInput($kind, BackupTrigger::Command)))
            ->toThrow(ValidationException::class, BackupPlaces::PAUSED);
    }

    expect(BackupRun::query()->count())->toBe(0);
});

it('starts nothing on the schedule while paused', function (): void {
    ($this->pause)();
    $due = app(DueBackupWork::class);
    $now = CarbonImmutable::parse('2026-10-18 03:00:00', 'UTC');

    expect($due->backupDue($now))->toBeFalse()
        ->and($due->pruneDue($now))->toBeFalse();

    BackupDestination::query()->update(['paused_at' => null]);

    expect($due->backupDue($now))->toBeTrue();
});

it('refuses to add or change keys and places while paused', function (): void {
    ($this->pause)();

    expect(fn () => app(AddRecoveryKey::class)->execute(new AddRecoveryKeyInput('Second key')))
        ->toThrow(ValidationException::class, BackupPlaces::PAUSED)
        ->and(BackupKey::query()->count())->toBe(3);
});

it('still checks a recovery key while paused: reading stays allowed', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    BackupDestination::query()->update(['paused_at' => now()]);

    $checked = app(CheckRecoveryKey::class)->execute(new CheckRecoveryKeyInput(RecoveryKey::generate()));

    expect($checked->opens)->toBe([])
        ->and($checked->doesNotOpen)->toBe(['Place 1'])
        ->and(app(BackupPlaces::class)->isPaused())->toBeTrue();
});

it('asks the classes that own them whether backups are set up, and for the machine key (issue #135)', function (): void {
    $places = app(BackupPlaces::class);

    expect($places->isSetUp())->toBe(app(BackupSetup::class)->isFinished())->toBeFalse()
        ->and($places->machineKey())->toBeNull();

    $this->backups = RunnableBackups::atFolders(1);

    expect($places->isSetUp())->toBeTrue()
        ->and(app(BackupSetup::class)->isFinished())->toBeTrue()
        ->and($places->machineKey())->toBe(app(BackupKeys::class)->machineKey());
});

it('resumes every paused place when this server is the site, logging each', function (): void {
    [$first, $second] = ($this->pause)();

    $names = app(ConfirmRestoredSite::class)->execute(new ConfirmRestoredSiteInput(RestoredSiteAnswer::ThisIsTheSite));
    $logged = DB::table('security_events')->where('type', 'backup.destination_changed')->orderBy('id')->pluck('context')->map(fn (string $context): array => json_decode($context, true))->all();

    expect($names)->toBe(['This server', 'Offsite'])
        ->and(BackupDestination::query()->whereNotNull('paused_at')->count())->toBe(0)
        ->and($first->fresh()->state)->toBe(BackupDestinationState::Ready)
        ->and(app(BackupPlaces::class)->isPaused())->toBeFalse()
        ->and(array_column($logged, 'destination'))->toEqualCanonicalizing([$first->id, $second->id])
        ->and(array_column($logged, 'fields'))->toBe([['paused'], ['paused']]);
});

it('removes every paused place when this is a copy, keeping their keys as history and their backups where they are', function (): void {
    [$first, $second] = ($this->pause)();

    $names = app(ConfirmRestoredSite::class)->execute(new ConfirmRestoredSiteInput(RestoredSiteAnswer::ThisIsACopy));

    expect($names)->toBe(['This server', 'Offsite'])
        ->and($first->fresh()->state)->toBe(BackupDestinationState::Removed)
        ->and($second->fresh()->state)->toBe(BackupDestinationState::Removed)
        ->and($second->fresh()->credentials)->toBeNull()
        ->and($second->fresh()->removed_at)->not->toBeNull()
        ->and($second->fresh()->paused_at)->toBeNull()
        ->and(BackupKey::query()->count())->toBe(3)
        ->and(app(BackupSetup::class)->isFinished())->toBeFalse()
        ->and(DB::table('security_events')->where('type', 'backup.destination_removed')->count())->toBe(2);
});

it('refuses an answer when nothing is paused', function (): void {
    BackupRecords::destination();

    expect(fn () => app(ConfirmRestoredSite::class)->execute(new ConfirmRestoredSiteInput(RestoredSiteAnswer::ThisIsTheSite)))
        ->toThrow(ValidationException::class, 'Nothing is paused: backups already run from this server.');
});

it('writes nothing when authorization fails', function (): void {
    ($this->pause)();
    $editor = User::factory()->create()->assignRole('editor');
    $before = json_encode([DB::table('backup_destinations')->orderBy('id')->get(), DB::table('security_events')->get()], JSON_THROW_ON_ERROR);

    foreach (RestoredSiteAnswer::cases() as $answer) {
        expect(fn () => app(ConfirmRestoredSite::class)->execute(new ConfirmRestoredSiteInput($answer, (string) $editor->id)))
            ->toThrow(AuthorizationException::class, 'Only an administrator manages backups.');
    }

    expect(json_encode([DB::table('backup_destinations')->orderBy('id')->get(), DB::table('security_events')->get()], JSON_THROW_ON_ERROR))->toBe($before);
});

it('asks at the terminal, then resumes backups', function (): void {
    ($this->pause)();

    $this->artisan('teme:backup:restored')
        ->expectsChoice('Is this server now the site?', 'This server is now the site', ['This server is now the site', 'This is a copy: remove these backup places'])
        ->expectsOutput('Backups resume at This server and Offsite.')
        ->assertExitCode(0);

    expect(app(BackupPlaces::class)->isPaused())->toBeFalse();
});

it('takes the answer as an argument, and refuses a word it doesn’t know or nothing paused', function (): void {
    ($this->pause)();

    $this->artisan('teme:backup:restored', ['answer' => 'maybe'])->expectsOutput('Answer site or copy.')->assertExitCode(1);
    $this->artisan('teme:backup:restored', ['answer' => 'copy'])
        ->expectsOutput('Removed This server and Offsite from this site’s backup places. Their backups stay where they are. Set backups up for this copy with php artisan teme:backup:setup.')
        ->assertExitCode(0);
    $this->artisan('teme:backup:restored', ['answer' => 'site'])->expectsOutput('Nothing is paused: backups already run from this server.')->assertExitCode(1);
});
