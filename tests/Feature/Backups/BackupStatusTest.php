<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\BackupVerdict;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Queries\BackupStatus;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Status\Checks\BackupsSafe;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\VerifyDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['general' => ['timezone' => 'UTC'], BackupSettings::GROUP => ['hour' => '02:00']] as $group => $overrides) {
        SettingsGroupRecord::query()->updateOrCreate(['group_key' => $group], ['overrides' => $overrides, 'version' => 1]);
        Cache::memo()->forget(SettingsRepository::cacheKey($group));
    }

    VerifyDatabase::unnamed();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'UTC'));
    // Set up three days ago, at a folder on this server.
    $this->place = BackupRecords::destination();
    $this->key = BackupRecords::key($this->place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key', 'added_at' => now()->subDays(3)]);
    $this->verdict = fn (?CarbonImmutable $now = null): BackupVerdict => app(BackupStatus::class)->verdict($now);
});

it('is red, and only red, before backups are set up, as BackupPlaces says', function (): void {
    expect(app(BackupPlaces::class)->isSetUp())->toBeTrue();

    $this->place->keys()->delete();
    $verdict = ($this->verdict)();

    expect(app(BackupPlaces::class)->isSetUp())->toBeFalse()
        ->and($verdict->level())->toBe(BackupVerdict::RED)
        ->and($verdict->problems)->toBe(['Nothing on this site is backed up yet. Set backups up on Tools › Backups.'])
        ->and($verdict->alerting)->toBe([])
        ->and($verdict->cautions)->toBe([]);
});

it('is amber the first night: no backup yet, the first within a minute, or running now', function (): void {
    $this->key->update(['added_at' => now()]);

    expect(($this->verdict)()->level())->toBe(BackupVerdict::AMBER)
        ->and(($this->verdict)()->cautions[0])->toBe('No backup has finished yet. The first starts within a minute.');

    BackupRecords::run(['state' => BackupRunState::Running, 'ended_at' => null]);

    expect(($this->verdict)()->cautions[0])->toBe('No backup has finished yet: the first is running now.')
        ->and(($this->verdict)()->problems)->toBe([]);
});

it('is red when the last backup failed, saying why, and that one emails; a backup after clears it', function (): void {
    BackupRecords::run(['state' => BackupRunState::Failed, 'message' => 'No place answered, so nothing was backed up.']);
    $verdict = ($this->verdict)();

    expect($verdict->problems)->toBe(['The last backup failed: No place answered, so nothing was backed up.'])
        ->and($verdict->alerting)->toBe($verdict->problems)
        ->and($verdict->lastGoodBackup)->toBeNull();

    BackupRecords::run(['started_at' => now(), 'ended_at' => now()]);

    expect(($this->verdict)()->problems)->toBe([])
        ->and(($this->verdict)()->lastGoodBackup?->toIso8601String())->toBe('2026-10-18T10:00:00+00:00');
});

it('is red when a copy or a check of the last backup failed', function (): void {
    $backup = BackupRecords::run(['started_at' => now()->subHours(2), 'ended_at' => now()->subHours(2)]);
    BackupRecords::run(['kind' => BackupRunKind::Copy, 'parent_id' => $backup->id, 'destination_id' => $this->place->id, 'state' => BackupRunState::Failed, 'message' => 'Restic couldn’t finish at Place 2.', 'started_at' => now()->subHours(2), 'ended_at' => now()->subHours(2)]);

    expect(($this->verdict)()->problems)->toBe(['The last backup didn’t reach every place: Restic couldn’t finish at Place 2.']);

    $next = BackupRecords::run(['started_at' => now()->subHour(), 'ended_at' => now()->subHour()]);
    BackupRecords::run(['kind' => BackupRunKind::Check, 'parent_id' => $next->id, 'destination_id' => $this->place->id, 'state' => BackupRunState::Failed, 'message' => 'restic found damage in the backups at Place 1. Run restic check there to see it.', 'started_at' => now()->subHour(), 'ended_at' => now()->subHour()]);

    expect(($this->verdict)()->problems)->toBe(['A check of the last backup failed: restic found damage in the backups at Place 1. Run restic check there to see it.'])
        ->and(($this->verdict)()->alerting)->toHaveCount(1);
});

it('is red when no backup has started since the hour came, two hours on', function (): void {
    BackupRecords::run(['started_at' => CarbonImmutable::parse('2026-10-17 02:05', 'UTC'), 'ended_at' => CarbonImmutable::parse('2026-10-17 02:20', 'UTC')]);

    expect(($this->verdict)(CarbonImmutable::parse('2026-10-18 03:59', 'UTC'))->problems)->toBe([]);

    $verdict = ($this->verdict)(CarbonImmutable::parse('2026-10-18 04:01', 'UTC'));

    expect($verdict->problems)->toBe(['No backup has started since 18 October 2026 at 02:00: the server’s scheduler may have stopped. It must run php artisan schedule:run every minute.'])
        ->and($verdict->alerting)->toBe($verdict->problems);
});

it('is red when the last prune, read-back or test restore failed, until one succeeds', function (): void {
    BackupRecords::run(['started_at' => now()->subHours(3), 'ended_at' => now()->subHours(3)]);

    foreach ([BackupRunKind::Prune, BackupRunKind::ReadBack, BackupRunKind::TestRestore] as $kind) {
        BackupRecords::run(['kind' => $kind, 'state' => BackupRunState::Failed, 'message' => "{$kind->value} went wrong."]);
    }

    expect(($this->verdict)()->problems)->toBe([
        'The last weekly removal of old backups failed: prune went wrong.',
        'The last nightly read-back failed: read-back went wrong.',
        'The last weekly test restore failed: test-restore went wrong.',
    ]);

    BackupRecords::run(['kind' => BackupRunKind::ReadBack, 'started_at' => now(), 'ended_at' => now()]);

    expect(($this->verdict)()->problems)->not->toContain('The last nightly read-back failed: read-back went wrong.')
        ->and(($this->verdict)()->problems)->toHaveCount(2);
});

it('is amber while every place is on this server, or no spare database is named, and green when neither', function (): void {
    BackupRecords::run();

    expect(($this->verdict)()->level())->toBe(BackupVerdict::AMBER)
        ->and(($this->verdict)()->cautions)->toBe([
            'Every backup is on this server, which a failed disk or a lost server would take with it. Add a place off this server.',
            'Backups aren’t test-restored each week: no spare database is named. At the server, name one as TEME_VERIFY_DATABASE.',
        ]);

    BackupRecords::destination(['kind' => BackupDestinationKind::S3]);
    config(['database.connections.teme_verify.database' => 'teme_verify']);

    expect(($this->verdict)()->level())->toBeNull()
        ->and(($this->verdict)()->cautions)->toBe([])
        ->and(app(BackupsSafe::class)->banner())->toBeNull();
});

it('shows administrators the verdict as the banner, red at 90 and amber at 20, and editors nothing of it', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    $editor = User::factory()->create()->assignRole('editor');
    BackupRecords::run();

    $amber = app(BackupsSafe::class)->banner();

    expect($amber?->level)->toBe('warning')
        ->and($amber?->priority)->toBe(20)
        ->and($amber?->message)->toBe(BackupStatus::OFF_SERVER);

    $this->place->keys()->delete();
    $red = app(BackupsSafe::class)->banner();

    expect($red?->level)->toBe('error')
        ->and($red?->priority)->toBe(90)
        ->and($red?->actionUrl)->toBe(route('tools.backups.index'))
        ->and(app(StatusChecks::class)->forUser($admin)?->message)->toBe(BackupStatus::NOT_SET_UP)
        ->and(app(StatusChecks::class)->forUser($editor)?->message)->not->toBe(BackupStatus::NOT_SET_UP);
});

it('reads only the backups’ own tables, in four statements, and never starts restic', function (): void {
    BackupRecords::run();
    ($this->verdict)();
    Process::fake();
    DB::enableQueryLog();

    ($this->verdict)();

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'backup_'))->count())->toBeLessThanOrEqual(4);

    Process::assertNothingRan();
});
