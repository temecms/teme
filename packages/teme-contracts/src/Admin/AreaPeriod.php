<?php

/**
 * The statistics window core hands a plugin's area: its id, and the start
 * and end dates of this period and the one to compare it with, as Y-m-d in
 * the person's time zone.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Admin;

use InvalidArgumentException;

/**
 * The statistics window core hands a plugin's area: its id, and the start
 * and end dates of this period and the one to compare it with, as Y-m-d in
 * the site's time zone. Plugins never invent a period; they only read
 * these dates.
 *
 * @since next
 */
final readonly class AreaPeriod
{
    /**
     * The six periods core offers; plugins cannot add.
     *
     * @since next
     */
    public const string LAST_7_DAYS = 'last_7_days';

    /** @since next */
    public const string LAST_30_DAYS = 'last_30_days';

    /** @since next */
    public const string LAST_90_DAYS = 'last_90_days';

    /** @since next */
    public const string THIS_MONTH = 'this_month';

    /** @since next */
    public const string LAST_MONTH = 'last_month';

    /** @since next */
    public const string THIS_YEAR = 'this_year';

    /**
     * @throws InvalidArgumentException for an unknown id or a date that is not Y-m-d
     *
     * @since next
     */
    public function __construct(
        public string $id,
        public string $start,
        public string $end,
        public string $compareStart,
        public string $compareEnd,
    ) {
        if (! in_array($id, self::ids(), true)) {
            throw new InvalidArgumentException('A period’s id must be one of the six core offers.');
        }

        foreach ([$start, $end, $compareStart, $compareEnd] as $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException('A period’s dates must be Y-m-d.');
            }
        }
    }

    /**
     * The period ids, in the select's order.
     *
     * @return list<string>
     *
     * @since next
     */
    public static function ids(): array
    {
        return [
            self::LAST_7_DAYS,
            self::LAST_30_DAYS,
            self::LAST_90_DAYS,
            self::THIS_MONTH,
            self::LAST_MONTH,
            self::THIS_YEAR,
        ];
    }
}
