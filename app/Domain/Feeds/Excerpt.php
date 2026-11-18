<?php

/**
 * An item's excerpt when its writer wrote none: the first words of the body's plain text, as WordPress's automatic excerpt takes the first 55, and an ellipsis when it cut.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds;

use App\Domain\Cards\PlainText;

final class Excerpt
{
    public const int WORDS = 55;

    /**
     * @param  array<mixed>  $document
     */
    public static function of(array $document): string
    {
        $words = preg_split('/\s+/u', PlainText::of($document), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($words) <= self::WORDS
            ? implode(' ', $words)
            : implode(' ', array_slice($words, 0, self::WORDS)).'…';
    }
}
