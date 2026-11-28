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
use DateTimeImmutable;
use DateTimeZone;
use Teme\Contracts\Admin\AreaPeriod;

it('builds the last 30 days and the 30 days before in the person’s time zone', function (): void {
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
