<?php

/**
 * Reads a price written in US dollars (I-1303), such as 3 or 0.125, as whole
 * micro-dollars, exactly: no floating point, so a price typed or listed is
 * the price charged.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

final class Dollars
{
    private const string PATTERN = '/^(\d{1,6})(?:\.(\d{1,6}))?$/';

    /**
     * The micro-dollars a price written in dollars stands for, or null when
     * the text isn't one: a sign, a separator or more than six decimals.
     */
    public static function toMicro(string $dollars): ?int
    {
        if (preg_match(self::PATTERN, trim($dollars), $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 1_000_000 + (int) str_pad($match[2] ?? '', 6, '0');
    }
}
