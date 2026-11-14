<?php

declare(strict_types=1);

use App\Domain\Backups\KeptRuns;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\SnapshotRun;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    // A run made at $time (UTC), with all four parts unless $complete is false.
    $this->run = fn (string $id, string $time, bool $complete = true): SnapshotRun => new SnapshotRun(
        $id,
        CarbonImmutable::parse($time, 'UTC'),
        $complete ? array_fill_keys(BackupSnapshots::PARTS, str_repeat('a', 64)) : [BackupSnapshots::DATABASE => str_repeat('a', 64)],
        [str_repeat('a', 64)],
    );
});

it('keeps the newest backup of each of the last seven days that have one', function (): void {
    $runs = [];

    foreach (range(0, 9) as $daysAgo) {
        $day = CarbonImmutable::parse('2026-10-18', 'UTC')->subDays($daysAgo)->format('Y-m-d');
        $runs[] = ($this->run)("night-{$day}", "{$day} 02:00");
        $runs[] = ($this->run)("noon-{$day}", "{$day} 12:00");
    }

    expect(KeptRuns::choose($runs, 7, 0, 0, 'UTC'))->toBe([
        'noon-2026-10-18', 'noon-2026-10-17', 'noon-2026-10-16', 'noon-2026-10-15',
        'noon-2026-10-14', 'noon-2026-10-13', 'noon-2026-10-12',
    ]);
});

it('then one from each of four weeks before them, then one from each of twelve months before those', function (): void {
    $runs = [];
    $day = CarbonImmutable::parse('2025-09-01 02:00', 'UTC');

    while ($day->lessThanOrEqualTo(CarbonImmutable::parse('2026-10-18 02:00', 'UTC'))) {
        $runs[] = ($this->run)($day->format('Y-m-d'), $day->format('Y-m-d H:i'));
        $day = $day->addDay();
    }

    // 2026-10-18 is a Sunday: the seven days are that ISO week, Monday 12 to Sunday 18.
    expect(KeptRuns::choose($runs, 7, 4, 12, 'UTC'))->toBe([
        '2026-10-18', '2026-10-17', '2026-10-16', '2026-10-15', '2026-10-14', '2026-10-13', '2026-10-12',
        '2026-10-11', '2026-10-04', '2026-09-27', '2026-09-20',
        '2026-09-13', '2026-08-31', '2026-07-31', '2026-06-30', '2026-05-31', '2026-04-30',
        '2026-03-31', '2026-02-28', '2026-01-31', '2025-12-31', '2025-11-30', '2025-10-31',
    ]);
});

it('keeps only complete backups', function (): void {
    $runs = [
        ($this->run)('half', '2026-10-18 02:00', complete: false),
        ($this->run)('whole', '2026-10-17 02:00'),
    ];

    expect(KeptRuns::choose($runs, 7, 4, 12, 'UTC'))->toBe(['whole']);
});

it('counts days in the site’s time zone', function (): void {
    // 23:30 and 00:30 UTC are one day in Lagos, an hour ahead.
    $runs = [($this->run)('late', '2026-10-17 23:30'), ($this->run)('later', '2026-10-18 00:30')];

    expect(KeptRuns::choose($runs, 2, 0, 0, 'UTC'))->toBe(['later', 'late'])
        ->and(KeptRuns::choose($runs, 2, 0, 0, 'Africa/Lagos'))->toBe(['later']);
});
