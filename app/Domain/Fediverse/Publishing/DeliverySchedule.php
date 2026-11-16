<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing;

/**
 * When a delivery that failed for a reason that may pass is tried again
 * (proposal, P1): at once, then after 1 minute, 5 minutes, 30 minutes,
 * 2 hours, 6 hours, 12 hours, 24 hours and 24 hours, about three days in
 * all; a server failing for 7 days is skipped until it answers again.
 */
final class DeliverySchedule
{
    /** The wait before each try after the first, in seconds. */
    public const array DELAYS = [60, 300, 1800, 7200, 21600, 43200, 86400, 86400];

    /** How long a server may fail before deliveries to it are skipped. */
    public const int SKIP_AFTER_DAYS = 7;

    /** How often a skipped server is tried once more, to learn whether it answers again. */
    public const int PROBE_SECONDS = 86400;

    /**
     * The seconds to wait before the next try, after this many tries, or
     * null when none is left.
     */
    public static function delayAfter(int $attempts): ?int
    {
        return self::DELAYS[$attempts - 1] ?? null;
    }

    /**
     * How many tries a delivery gets in all.
     */
    public static function tries(): int
    {
        return count(self::DELAYS) + 1;
    }
}
