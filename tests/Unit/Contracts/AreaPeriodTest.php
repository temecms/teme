<?php

/**
 * The six statistics periods and the dates AreaContext hands a plugin.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\Area\AreaPeriods;
use Teme\Contracts\Admin\AreaPeriod;

it('builds the last 30 days and the 30 days before in the site’s time zone', function (): void {
    $now = new DateTimeImmutable('2026-11-28 15:00:00', new DateTimeZone('Africa/Lagos'));
    $period = AreaPeriods::of(AreaPeriod::LAST_30_DAYS, 'Africa/Lagos', $now);

    expect($period->id)->toBe(AreaPeriod::LAST_30_DAYS)
        ->and($period->start)->toBe('2026-10-30')
        ->and($period->end)->toBe('2026-11-28')
        ->and($period->compareStart)->toBe('2026-09-30')
        ->and($period->compareEnd)->toBe('2026-10-29');
});

it('builds this month against the same days last month', function (): void {
    $now = new DateTimeImmutable('2026-11-18 12:00:00', new DateTimeZone('UTC'));
    $period = AreaPeriods::of(AreaPeriod::THIS_MONTH, 'UTC', $now);

    expect($period->start)->toBe('2026-11-01')
        ->and($period->end)->toBe('2026-11-18')
        ->and($period->compareStart)->toBe('2026-10-01')
        ->and($period->compareEnd)->toBe('2026-10-18');
});

it('builds this month on the 31st against the last day of a shorter prior month', function (): void {
    $now = new DateTimeImmutable('2026-03-31 12:00:00', new DateTimeZone('UTC'));
    $period = AreaPeriods::of(AreaPeriod::THIS_MONTH, 'UTC', $now);

    expect($period->start)->toBe('2026-03-01')
        ->and($period->end)->toBe('2026-03-31')
        ->and($period->compareStart)->toBe('2026-02-01')
        ->and($period->compareEnd)->toBe('2026-02-28');
});

it('builds last month from a January date against December', function (): void {
    $now = new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC'));
    $period = AreaPeriods::of(AreaPeriod::LAST_MONTH, 'UTC', $now);

    expect($period->start)->toBe('2025-12-01')
        ->and($period->end)->toBe('2025-12-31')
        ->and($period->compareStart)->toBe('2025-11-01')
        ->and($period->compareEnd)->toBe('2025-11-30');
});

it('builds this year on a leap day against the prior 28 February', function (): void {
    $now = new DateTimeImmutable('2024-02-29 12:00:00', new DateTimeZone('UTC'));
    $period = AreaPeriods::of(AreaPeriod::THIS_YEAR, 'UTC', $now);

    expect($period->start)->toBe('2024-01-01')
        ->and($period->end)->toBe('2024-02-29')
        ->and($period->compareStart)->toBe('2023-01-01')
        ->and($period->compareEnd)->toBe('2023-02-28');
});

it('builds the last 7 and 90 days', function (): void {
    $now = new DateTimeImmutable('2026-11-28 12:00:00', new DateTimeZone('UTC'));

    expect(AreaPeriods::of(AreaPeriod::LAST_7_DAYS, 'UTC', $now)->start)->toBe('2026-11-22')
        ->and(AreaPeriods::of(AreaPeriod::LAST_90_DAYS, 'UTC', $now)->start)->toBe('2026-08-31');
});

it('defaults an unknown ask to the last 30 days', function (): void {
    expect(AreaPeriods::id('forever'))->toBe(AreaPeriod::LAST_30_DAYS)
        ->and(AreaPeriods::id(null))->toBe(AreaPeriod::LAST_30_DAYS);
});

it('refuses a period with an unknown id or a bad date', function (): void {
    expect(fn () => new AreaPeriod('forever', '2026-01-01', '2026-01-07', '2025-12-25', '2025-12-31'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new AreaPeriod(AreaPeriod::LAST_7_DAYS, '1 Jan', '2026-01-07', '2025-12-25', '2025-12-31'))
        ->toThrow(InvalidArgumentException::class);
});
