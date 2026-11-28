<?php

/**
 * The six statistics periods an area page offers: their labels, the window
 * in the site's time zone, and the matching earlier window to compare.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Teme\Contracts\Admin\AreaPeriod;

final class AreaPeriods
{
    /**
     * Resolves a requested period id to one of the six, defaulting to the
     * last 30 days when the request names none or an unknown one.
     */
    public static function id(?string $requested): string
    {
        return in_array($requested, AreaPeriod::ids(), true) ? (string) $requested : AreaPeriod::LAST_30_DAYS;
    }

    /**
     * The period for this id in the given time zone, as of now.
     */
    public static function of(string $id, string $timezone, ?DateTimeImmutable $now = null): AreaPeriod
    {
        $id = self::id($id);
        $zone = new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        $today = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone)->setTime(0, 0, 0);

        return match ($id) {
            AreaPeriod::LAST_7_DAYS => self::lastDays(7, $today, $id),
            AreaPeriod::LAST_90_DAYS => self::lastDays(90, $today, $id),
            AreaPeriod::THIS_MONTH => self::thisMonth($today),
            AreaPeriod::LAST_MONTH => self::lastMonth($today),
            AreaPeriod::THIS_YEAR => self::thisYear($today),
            default => self::lastDays(30, $today, AreaPeriod::LAST_30_DAYS),
        };
    }

    /**
     * The select's options: id and label, in order.
     *
     * @return list<array{id: string, label: string}>
     */
    public static function options(): array
    {
        return [
            ['id' => AreaPeriod::LAST_7_DAYS, 'label' => __('Last 7 days')],
            ['id' => AreaPeriod::LAST_30_DAYS, 'label' => __('Last 30 days')],
            ['id' => AreaPeriod::LAST_90_DAYS, 'label' => __('Last 90 days')],
            ['id' => AreaPeriod::THIS_MONTH, 'label' => __('This month')],
            ['id' => AreaPeriod::LAST_MONTH, 'label' => __('Last month')],
            ['id' => AreaPeriod::THIS_YEAR, 'label' => __('This year')],
        ];
    }

    /**
     * The period's label in lower case for figure titles, such as "last 30 days".
     */
    public static function lower(string $id): string
    {
        foreach (self::options() as $option) {
            if ($option['id'] === $id) {
                return mb_strtolower($option['label']);
            }
        }

        return mb_strtolower(__('Last 30 days'));
    }

    /**
     * The comparison phrase for screen readers and chart legends, such as
     * "the 30 days before".
     */
    public static function before(string $id): string
    {
        return match (self::id($id)) {
            AreaPeriod::LAST_7_DAYS => __('the 7 days before'),
            AreaPeriod::LAST_90_DAYS => __('the 90 days before'),
            AreaPeriod::THIS_MONTH => __('the same days last month'),
            AreaPeriod::LAST_MONTH => __('the month before'),
            AreaPeriod::THIS_YEAR => __('the same days last year'),
            default => __('the 30 days before'),
        };
    }

    /**
     * The chart legend for the dashed earlier series, such as "The 30 days before".
     */
    public static function beforeLabel(string $id): string
    {
        return match (self::id($id)) {
            AreaPeriod::LAST_7_DAYS => __('The 7 days before'),
            AreaPeriod::LAST_90_DAYS => __('The 90 days before'),
            AreaPeriod::THIS_MONTH => __('The same days last month'),
            AreaPeriod::LAST_MONTH => __('The month before'),
            AreaPeriod::THIS_YEAR => __('The same days last year'),
            default => __('The 30 days before'),
        };
    }

    /**
     * A date as people read it, such as "Oct 18, 2026".
     */
    public static function shown(string $ymd, string $timezone): string
    {
        $zone = new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, $zone) ?: new DateTimeImmutable('now', $zone);

        return $date->format('M j, Y');
    }

    private static function lastDays(int $days, DateTimeImmutable $today, string $id): AreaPeriod
    {
        $end = $today;
        $start = $today->sub(new DateInterval('P'.($days - 1).'D'));
        $compareEnd = $start->sub(new DateInterval('P1D'));
        $compareStart = $compareEnd->sub(new DateInterval('P'.($days - 1).'D'));

        return new AreaPeriod($id, $start->format('Y-m-d'), $end->format('Y-m-d'), $compareStart->format('Y-m-d'), $compareEnd->format('Y-m-d'));
    }

    private static function thisMonth(DateTimeImmutable $today): AreaPeriod
    {
        $start = $today->modify('first day of this month');
        $end = $today;
        $day = (int) $today->format('j');
        $lastMonthEnd = $start->sub(new DateInterval('P1D'));
        $lastMonthStart = $lastMonthEnd->modify('first day of this month');
        $compareStart = $lastMonthStart;
        $lastDay = (int) $lastMonthEnd->format('j');
        $compareEnd = $lastMonthStart->modify('+'.(min($day, $lastDay) - 1).' days');

        return new AreaPeriod(AreaPeriod::THIS_MONTH, $start->format('Y-m-d'), $end->format('Y-m-d'), $compareStart->format('Y-m-d'), $compareEnd->format('Y-m-d'));
    }

    private static function lastMonth(DateTimeImmutable $today): AreaPeriod
    {
        $start = $today->modify('first day of last month');
        $end = $today->modify('last day of last month');
        $beforeStart = $start->modify('first day of last month');
        $beforeEnd = $start->sub(new DateInterval('P1D'));

        return new AreaPeriod(AreaPeriod::LAST_MONTH, $start->format('Y-m-d'), $end->format('Y-m-d'), $beforeStart->format('Y-m-d'), $beforeEnd->format('Y-m-d'));
    }

    private static function thisYear(DateTimeImmutable $today): AreaPeriod
    {
        $start = $today->setDate((int) $today->format('Y'), 1, 1);
        $end = $today;
        $compareStart = $start->modify('-1 year');
        $month = (int) $today->format('n');
        $day = (int) $today->format('j');
        $compareEnd = $compareStart->setDate((int) $compareStart->format('Y'), $month, min($day, (int) $compareStart->setDate((int) $compareStart->format('Y'), $month, 1)->format('t')));

        return new AreaPeriod(AreaPeriod::THIS_YEAR, $start->format('Y-m-d'), $end->format('Y-m-d'), $compareStart->format('Y-m-d'), $compareEnd->format('Y-m-d'));
    }
}
