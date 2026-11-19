<?php

/**
 * A WordPress slug as Teme keeps it (I-397): percent-encoding decoded, so
 * caf%c3%a9 stays café at the same address, and anything Teme's slugs
 * can't hold turned into single hyphens, so its caller can say the address
 * changed. People's author addresses use it too.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Terms;

final class WordPressSlugs
{
    /** Teme's slug rule, as ValidatesTerms holds it: letters and numbers of any script, lower-case, joined by single hyphens. */
    public const string RULE = '/^[\p{Ll}\p{Lo}\p{N}]+(?:-[\p{Ll}\p{Lo}\p{N}]+)*$/u';

    /**
     * The slug Teme gives a WordPress slug, at most $max characters, and
     * whether it is WordPress's own, so its address stays the same; an
     * empty slug when nothing of it is usable.
     *
     * @return array{slug: string, kept: bool}
     */
    public static function fromWordPress(string $slug, int $max = 190): array
    {
        // A byte that isn't UTF-8 once decoded becomes a question mark, then a hyphen.
        $decoded = mb_strtolower(mb_scrub(rawurldecode($slug), 'UTF-8'), 'UTF-8');

        if (mb_strlen($decoded) <= $max && preg_match(self::RULE, $decoded) === 1) {
            return ['slug' => $decoded, 'kept' => true];
        }

        $made = trim((string) preg_replace('/[^\p{Ll}\p{Lo}\p{N}]+/u', '-', $decoded), '-');

        return ['slug' => rtrim(mb_substr($made, 0, $max), '-'), 'kept' => false];
    }
}
