<?php

/**
 * A commenter's picture (I-269): their initials on a colour made from their
 * name, never a photo fetched from another service and never from their
 * email address, which is never shown.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

use Normalizer;

final class CommentPicture
{
    /** How many colours a picture may take. */
    public const int HUES = 8;

    /**
     * The first letter of the name's first word and of its last, whole
     * graphemes and upper-cased, from the name as NFC; words without a letter
     * are skipped, and a name with none gives `?`.
     */
    public static function initials(string $name): string
    {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_C);
        $words = array_values(array_filter(
            preg_split('/\s+/u', $normalized === false ? $name : $normalized) ?: [],
            fn (string $word): bool => preg_match('/\p{L}/u', $word) === 1,
        ));

        if ($words === []) {
            return '?';
        }

        $first = self::letter($words[0]);

        return count($words) === 1 ? $first : $first.self::letter($words[count($words) - 1]);
    }

    /**
     * The picture's colour, 0 to HUES - 1, from the name alone, whatever its
     * case: the number core's script gives the same name.
     */
    public static function hue(string $name): int
    {
        $hash = 2166136261;

        foreach (mb_str_split(mb_strtolower($name)) as $character) {
            // FNV-1a over code points, kept to 32 bits, as the prototype's Math.imul loop.
            $hash = (($hash ^ mb_ord($character)) * 16777619) & 0xFFFFFFFF;
        }

        return $hash % self::HUES;
    }

    /**
     * A word's first grapheme, upper-cased: Ọ stays Ọ.
     */
    private static function letter(string $word): string
    {
        $grapheme = grapheme_substr($word, 0, 1);

        return mb_strtoupper($grapheme === false ? mb_substr($word, 0, 1) : $grapheme);
    }
}
