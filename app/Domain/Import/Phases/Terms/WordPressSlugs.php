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

use App\Domain\Contents\Slugs;

final class WordPressSlugs
{
    /** Teme's slug rule, as Slugs holds it. */
    public const string RULE = Slugs::RULE;

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
        $decoded = Slugs::normal(mb_strtolower(mb_scrub(rawurldecode($slug), 'UTF-8'), 'UTF-8'));

        if (mb_strlen($decoded) <= $max && preg_match(self::RULE, $decoded) === 1) {
            return ['slug' => $decoded, 'kept' => true];
        }

        $made = (string) preg_replace(['/['.Slugs::INVISIBLE_MARKS.']/u', '/[^\p{Ll}\p{Lo}\p{N}\p{Mn}\p{Mc}]+/u'], ['', '-'], $decoded);
        // A mark belongs to the letter before it; one with none goes, and the hyphens it leaves with it.
        $made = trim((string) preg_replace(['/(?<=^|-)[\p{Mn}\p{Mc}]+/u', '/-{2,}/'], ['', '-'], $made), '-');

        return ['slug' => rtrim(mb_substr($made, 0, $max), '-'), 'kept' => false];
    }
}
