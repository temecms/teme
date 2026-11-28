<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupMode;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\BackupVerdict;
use App\Domain\Backups\Queries\BackupsScreen;
use App\Domain\Backups\Queries\BackupStatus;
use App\Domain\Backups\Verify\SpareDatabase;
use App\Domain\Backups\Verify\TemporaryDatabase;
use App\Domain\Deployment\Health\Checks\RestoreAccount;
use App\Domain\Deployment\Health\Checks\TestRestoreDatabase;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Status\Checks\BackupsSafe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\ScreenRecords;
use Tests\Fixtures\Backups\VerifyDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['general' => ['timezone' => 'UTC'], BackupSettings::GROUP => ['hour' => '02:00']] as $group => $overrides) {
        SettingsGroupRecord::query()->updateOrCreate(['group_key' => $group], ['overrides' => $overrides, 'version' => 1]);
        Cache::memo()->forget(SettingsRepository::cacheKey($group));
    }

    VerifyDatabase::unnamed();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'UTC'));
});

it('runs Teme’s own backups unless TEME_BACKUPS says the host does them', function (): void {
    expect(BackupMode::isHost())->toBeFalse();

    config(['teme.backup_mode' => 'host']);

    expect(BackupMode::isHost())->toBeTrue();
});

it('accepts teme and host, in any case and with spaces, and no value at all as teme', function (): void {
    config(['teme.backup_mode' => ' Host ']);
    expect(BackupMode::problemFor(' Host '))->toBeNull()
        ->and(BackupMode::isHost())->toBeTrue();

    config(['teme.backup_mode' => '']);
    expect(BackupMode::problemFor(''))->toBeNull()
        ->and(BackupMode::isHost())->toBeFalse();
});

it('shows one healthy state, with no warning, when the host does the backups and Teme’s is not set up', function (): void {
    config(['teme.backup_mode' => 'host']);

    $verdict = app(BackupStatus::class)->verdict();

    expect($verdict->level())->toBeNull()
        ->and($verdict->problems)->toBe([])
        ->and($verdict->cautions)->toBe([]);
});

it('asks for no spare database under host, even once Teme’s own backup is set up', function (): void {
    config(['teme.backup_mode' => 'host', 'database.connections.teme_restore.username' => 'teme_restore_role']);
    $place = BackupRecords::destination(['kind' => BackupDestinationKind::S3]);
    BackupRecords::key($place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key', 'added_at' => now()->subDays(3)]);
    BackupRecords::run(['started_at' => now()->subHours(3), 'ended_at' => now()->subHours(3)]);

    $verdict = app(BackupStatus::class)->verdict();

    expect($verdict->cautions)->not->toContain(BackupStatus::NO_SPARE)
        ->and($verdict->level())->toBeNull();
});

it('does not warn about the spare database in the doctor under host', function (): void {
    config(['teme.backup_mode' => 'host']);

    expect((new TestRestoreDatabase)->examine())->toBe([]);
});

it('keeps the spare database warning under teme', function (): void {
    expect((new TestRestoreDatabase)->examine())->not->toBe([]);
});

it('keeps the old verdict under teme when backups are not set up', function (): void {
    expect(app(BackupStatus::class)->verdict()->level())->toBe(BackupVerdict::RED);
});

it('leads Tools › Backups with one healthy line under host, while Teme’s own backup is not set up', function (): void {
    config(['teme.backup_mode' => 'host']);

    $screen = app(BackupsScreen::class)->verdict(CarbonImmutable::now());

    expect($screen)->toBe([
        'level' => 'green',
        'sentence' => 'Backups and restore tests are done by your host.',
        'more' => ['Teme’s own backup can still be set up as a second copy elsewhere.'],
    ]);
});

it('gives the screen the host line as its verdict, and keeps paused on its own state under host', function (): void {
    config(['teme.backup_mode' => 'host']);
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($admin)->get('/admin/tools/backups')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('setup.state', 'none')
        ->where('verdict.level', 'green')
        ->where('verdict.sentence', 'Backups and restore tests are done by your host.')
        ->where('verdict.more', ['Teme’s own backup can still be set up as a second copy elsewhere.']));

    ScreenRecords::place('This server', ['paused_at' => now()]);

    $this->actingAs($admin)->get('/admin/tools/backups')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('setup.state', 'paused')
        ->where('verdict', null));
});

it('says in the verdict that the weekly test restore has no restore account, once Teme’s own backup is set up under host', function (): void {
    config(['teme.backup_mode' => 'host', 'database.connections.teme_restore.username' => null]);
    $place = BackupRecords::destination(['kind' => BackupDestinationKind::S3]);
    BackupRecords::key($place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key', 'added_at' => now()->subDays(3)]);
    BackupRecords::run(['started_at' => now()->subHours(3), 'ended_at' => now()->subHours(3)]);

    expect(app(BackupStatus::class)->verdict()->cautions)->toBe([BackupStatus::NO_RESTORE_ACCOUNT]);

    config(['database.connections.teme_restore.username' => 'teme_restore_role']);

    expect(app(BackupStatus::class)->verdict()->cautions)->not->toContain(BackupStatus::NO_RESTORE_ACCOUNT);
});

it('checks the restore account in the doctor under host, and says nothing when there is none', function (): void {
    expect((new RestoreAccount)->examine())->toBe([]);

    config(['teme.backup_mode' => 'host', 'database.connections.teme_restore.username' => null]);
    expect((new RestoreAccount)->examine())->toBe([]);
});

it('warns in the doctor when the restore account is the site’s own role', function (): void {
    config(['teme.backup_mode' => 'host', 'database.connections.teme_restore' => [...DB::connection()->getConfig(), 'database' => 'postgres']]);
    DB::purge(TemporaryDatabase::CONNECTION);

    $findings = (new RestoreAccount)->examine();

    expect($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->message)->toContain('site’s own role');
});

it('warns in the doctor when the restore account is a superuser', function (): void {
    // The test's own role is a superuser here, and is the restore account too: the check reads what the server says.
    config(['teme.backup_mode' => 'host', 'database.connections.teme_restore' => [...DB::connection()->getConfig(), 'database' => 'postgres']]);
    DB::purge(TemporaryDatabase::CONNECTION);

    $messages = array_map(fn (Finding $finding): string => $finding->message, (new RestoreAccount)->examine());

    expect($messages)->toContain(collect($messages)->first(fn (string $message): bool => str_contains($message, 'is a superuser')));
});

it('uses an explicitly named spare database under host, and the temporary one only when none is named', function (): void {
    config(['teme.backup_mode' => 'host', 'database.connections.teme_verify.database' => null]);
    expect(SpareDatabase::testRestoreUsesTemporary())->toBeTrue();

    config(['database.connections.teme_verify.database' => 'teme_named_verify']);
    expect(SpareDatabase::testRestoreUsesTemporary())->toBeFalse();

    config(['teme.backup_mode' => 'teme']);
    expect(SpareDatabase::testRestoreUsesTemporary())->toBeFalse();
});

it('names the restore account, not the spare database, when the test restore is skipped under host', function (): void {
    config(['teme.backup_mode' => 'host', 'database.connections.teme_restore.username' => null, 'database.connections.teme_verify.database' => null]);

    $this->artisan('teme:backup:verify', ['--test-restore' => true])
        ->expectsOutputToContain('TEME_RESTORE_DB_USERNAME')
        ->assertFailed();
});

it('keeps the banner silent under host while Teme’s own backup is not set up', function (): void {
    config(['teme.backup_mode' => 'host']);

    expect(app(BackupsSafe::class)->banner())->toBeNull();
});
