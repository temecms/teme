<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\BackupWords;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Queries\BackupsScreen;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\ScreenRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Lagos is UTC+1 all year: 02:00 there is 01:00 UTC. Now is 10:00 in Lagos.
    ScreenRecords::settings(['general' => ['timezone' => 'Africa/Lagos'], BackupSettings::GROUP => ['hour' => '02:00']]);
    $this->now = CarbonImmutable::parse('2026-10-20 09:00', 'UTC');
    $this->travelTo($this->now);
    $this->screen = fn (): BackupsScreen => app(BackupsScreen::class);
});

it('says where setup stands: nothing yet, half done, or set up', function (): void {
    expect(($this->screen)()->setupState())->toBe(['state' => 'none', 'placeId' => null, 'placeName' => null]);

    $place = ScreenRecords::place('This server', recoveryLabel: null);

    expect(($this->screen)()->setupState())->toBe(['state' => 'unfinished', 'placeId' => $place->id, 'placeName' => 'This server']);

    BackupRecords::key($place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);

    expect(($this->screen)()->setupState())->toBe(['state' => 'set-up', 'placeId' => null, 'placeName' => null]);
});

it('says when a backup or a prune is running, and nothing otherwise', function (): void {
    $place = ScreenRecords::place();

    expect(($this->screen)()->running($this->now))->toBeNull();

    ScreenRecords::backup($place, '2026-10-20 08:58', BackupRunState::Running);

    expect(($this->screen)()->running($this->now))->toBe('A backup is running now, to This server. It started today at 09:58.');

    BackupRun::query()->update(['state' => BackupRunState::Finished->value, 'ended_at' => now()]);
    BackupRecords::run(['kind' => BackupRunKind::Prune, 'state' => BackupRunState::Running, 'started_at' => now()->subMinutes(3), 'ended_at' => null]);

    expect(($this->screen)()->running($this->now))->toBe('Removing old backups now. This started today at 09:57.');
});

it('leaves out the checks of a place no longer in use', function (): void {
    $place = ScreenRecords::place();
    $gone = ScreenRecords::place('Old disk', ['state' => BackupDestinationState::Removed, 'removed_at' => now(), 'credentials' => null]);
    ScreenRecords::check(BackupRunKind::Check, $gone, '2026-10-20 01:12', passed: false, message: 'Old disk didn’t answer.');
    ScreenRecords::check(BackupRunKind::Check, $place, '2026-10-20 01:11');

    expect(($this->screen)()->latestChecks(collect([$place]))->pluck('destination_id')->all())->toBe([$place->id]);
});

it('writes dates, durations, names and what’s kept in words', function (): void {
    // Set up, with no backup since tonight's hour: the next is due now.
    $place = ScreenRecords::place();
    $words = app(BackupWords::class);
    $at = fn (string $utc): CarbonImmutable => CarbonImmutable::parse($utc, 'UTC');

    expect($words->when($at('2026-10-20 01:14'), $this->now))->toBe('today at 02:14')
        ->and($words->when($at('2026-10-19 23:30'), $this->now))->toBe('today at 00:30')
        ->and($words->when($at('2026-10-19 01:14'), $this->now))->toBe('yesterday at 02:14')
        ->and($words->when($at('2026-10-14 01:14'), $this->now))->toBe('on 14 October at 02:14')
        ->and($words->when($at('2026-10-14 01:14'), $this->now, since: true))->toBe('14 October at 02:14')
        ->and($words->when($at('2025-12-31 23:30'), $this->now))->toBe('on 1 January at 00:30')
        ->and($words->when($at('2025-03-02 01:00'), $this->now))->toBe('on 2 March 2025 at 02:00')
        ->and($words->next($this->now))->toBe('within a minute')
        ->and(BackupWords::duration(40))->toBe('under a minute')
        ->and(BackupWords::duration(60))->toBe('1 minute')
        ->and(BackupWords::duration(14 * 60 + 20))->toBe('14 minutes')
        ->and(BackupWords::duration(3600))->toBe('1 hour')
        ->and(BackupWords::duration(3660))->toBe('1 hour 1 minute')
        ->and(BackupWords::duration(2 * 3600 + 5 * 60))->toBe('2 hours 5 minutes')
        ->and(BackupWords::kept(7, 4, 12))->toBe('Keeps a backup from each of the last 7 days, then one from each of 4 weeks before them, and one from each of 12 months before those.')
        ->and(BackupWords::kept(1, 1, 1))->toBe('Keeps a backup from the last day, then one from the week before them, and one from the month before those.')
        ->and(BackupWords::kept(7, 0, 12))->toBe('Keeps a backup from each of the last 7 days, and one from each of 12 months before them.')
        ->and(BackupWords::kept(7, 4, 0))->toBe('Keeps a backup from each of the last 7 days, then one from each of 4 weeks before them.')
        ->and(BackupWords::kept(30, 0, 0))->toBe('Keeps a backup from each of the last 30 days.')
        ->and(BackupWords::names(['A']))->toBe('A')
        ->and(BackupWords::names(['A', 'B']))->toBe('A and B')
        ->and(BackupWords::names(['A', 'B', 'C']))->toBe('A, B and C');

    // A backup already started since tonight's hour: the next is tomorrow's.
    ScreenRecords::backup($place, '2026-10-20 01:05');

    expect(app(BackupWords::class)->next($this->now))->toBe('tomorrow at 02:00');
});
