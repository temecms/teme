<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use function microtime;

/**
 * The clock the address check times its fifty seconds a job by, which a
 * test can make leap: after leap(), each reading comes that many seconds
 * after the one before, as on a machine too busy to check more than an
 * address a job. Unmoved, it is the real clock (check-clock.php).
 */
final class CheckClock
{
    /** Seconds each reading comes after the one before, on top of the real time between them. */
    private static float $leap = 0.0;

    /** Seconds the clock has leapt so far. */
    private static float $ahead = 0.0;

    /**
     * Makes each reading from now on come $seconds after the one before;
     * 0 puts the real clock back.
     */
    public static function leap(float $seconds): void
    {
        self::$leap = $seconds;
        self::$ahead = 0.0;
    }

    /**
     * The time now, in seconds, as microtime(true) gives it, plus every leap so far.
     */
    public static function now(): float
    {
        self::$ahead += self::$leap;

        return microtime(true) + self::$ahead;
    }
}
