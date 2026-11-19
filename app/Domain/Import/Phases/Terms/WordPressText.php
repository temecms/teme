<?php

/**
 * Text WordPress stored for display, as Teme keeps it: names WordPress
 * escaped once on the way in, decoded once; descriptions and biographies
 * it filtered as HTML, as plain text, which Teme's templates escape.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Terms;

final class WordPressText
{
    /**
     * A name with WordPress's escaping undone once, trimmed: "Night &amp;
     * Day" is "Night & Day", and an editor's own "&amp;amp;" stays "&amp;".
     */
    public static function decoded(mixed $text): string
    {
        return trim(html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * HTML as plain text: a paragraph's or a line's end kept as a line
     * break, every other tag removed, entities decoded once; null when
     * nothing is left.
     */
    public static function plain(mixed $html): ?string
    {
        $broken = (string) preg_replace('~</(p|div|li|h[1-6])>|<br\s*/?>~i', "\n", (string) $html);
        $text = self::decoded(strip_tags($broken));

        return $text === '' ? null : $text;
    }
}
