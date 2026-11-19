<?php

/**
 * How long an article takes to read (I-256), from its published revision's
 * word count, so it never drifts from the search text (I-250).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

final class ReadingTime
{
    /** The adult silent-reading average; Ghost and Medium use values near it. */
    public const int WORDS_PER_MINUTE = 238;

    /**
     * Whole minutes, rounded up, and never below one.
     */
    public static function minutes(int $words): int
    {
        return max(1, (int) ceil($words / self::WORDS_PER_MINUTE));
    }
}
