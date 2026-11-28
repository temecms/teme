<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupMode;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\BackupVerdict;
use App\Domain\Backups\Queries\BackupsScreen;
use App\Domain\Backups\Queries\BackupStatus;
use App\Domain\Deployment\Health\Checks\TestRestoreDatabase;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
});

it('runs Teme’s own backups unless TEME_BACKUPS says the host does them', function (): void {
    expect(BackupMode::isHost())->toBeFalse();

    config(['teme.backup_mode' => 'host']);

    expect(BackupMode::isHost())->toBeTrue();
});

it('stops the start in a plain sentence for an unknown TEME_BACKUPS value', function (): void {
    config(['teme.backup_mode' => 'hosted']);

    BackupMode::assertValid();
})->throws(InvalidArgumentException::class, 'TEME_BACKUPS is “hosted”, which Teme doesn’t know. Use teme or host.');

it('accepts teme and host, in any case and with spaces', function (): void {
    config(['teme.backup_mode' => ' Host ']);

    expect(fn () => BackupMode::assertValid())->not->toThrow(InvalidArgumentException::class);
});

it('shows one healthy state, with no warning, when the host does the backups and Teme’s is not set up', function (): void {
    config(['teme.backup_mode' => 'host']);

    $verdict = app(BackupStatus::class)->verdict();

    expect($verdict->level())->toBeNull()
        ->and($verdict->problems)->toBe([])
        ->and($verdict->cautions)->toBe([]);
});

it('asks for no spare database under host, even once Teme’s own backup is set up', function (): void {
    config(['teme.backup_mode' => 'host']);
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
        'more' => ['Teme’s own backup can still be set up as a second copy elsewhere, on Tools › Backups.'],
    ]);
});
