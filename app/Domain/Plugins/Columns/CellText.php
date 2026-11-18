<?php

/**
 * Cleans the text of a plugin's cell, for list columns and for the lists on
 * a plugin's settings page alike: control and direction characters become
 * spaces, runs of space one, and text longer than its limit is cut.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Columns;

final class CellText
{
    /**
     * The cleaned text, cut to $most characters with an ellipsis; '' when
     * nothing is left, null when the text can't be read.
     */
    public static function clean(string $text, int $most): ?string
    {
        $clean = preg_replace('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $text);

        if ($clean === null) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');

        if (mb_strlen($clean) > $most) {
            $clean = mb_substr($clean, 0, $most - 1).'…';
        }

        return $clean;
    }
}
