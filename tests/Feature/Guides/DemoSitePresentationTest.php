<?php

declare(strict_types=1);

use App\Domain\Backups\Queries\BackupStatus;
use App\Domain\Status\Checks\SchedulerRunning;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Testing\ContractsVersion;
use Tests\Support\DemoSite;

uses(RefreshDatabase::class);

/*
 * The demo site, as the guides' pictures show it: a released, live site whose
 * backups are set up and recent, so no notice about any of them is on screen.
 */

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->now = CarbonImmutable::parse('2026-11-20 09:30', 'UTC');
    CarbonImmutable::setTestNow($this->now);
    DemoSite::build();
    CarbonImmutable::setTestNow($this->now);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('reports the newest released version and the live site', function (): void {
    DemoSite::asReleased();

    expect(config('teme.version'))->toBe(ContractsVersion::highest())
        ->and(app()->isProduction())->toBeTrue()
        ->and(app(SchedulerRunning::class)->banner())->toBeNull();
});

it('has backups with nothing to warn about', function (): void {
    DemoSite::backedUp($this->now);

    $verdict = app(BackupStatus::class)->verdict($this->now);

    expect($verdict->problems)->toBe([])
        ->and($verdict->cautions)->toBe([])
        ->and($verdict->lastGoodBackup)->not->toBeNull();
});

it('shows the notice that nothing is backed up until backups are set up', function (): void {
    expect(app(BackupStatus::class)->verdict($this->now)->problems)->toBe([BackupStatus::NOT_SET_UP]);
});
