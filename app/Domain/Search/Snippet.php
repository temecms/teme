<?php

/**
 * A search result's snippet as safe HTML. PostgreSQL's ts_headline marks the
 * matches with two private-use characters; the text is escaped first, and
 * only then do the markers become <mark>, so nothing in the text is ever
 * markup. The one place search output is printed raw.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Search;

use Illuminate\Support\Str;

final class Snippet
{
    /** Where ts_headline starts a match. */
    public const string START = "\u{E000}";

    /** Where ts_headline ends one. */
    public const string STOP = "\u{E001}";

    /** How many words a snippet with no match in the text shows. */
    private const int WORDS = 35;

    /**
     * The headline as safe HTML: escaped, then its matches marked. A match
     * only in the title leaves the text unmarked, so it shows the text's
     * first words instead.
     */
    public static function fromHeadline(string $raw): string
    {
        if (! str_contains($raw, self::START)) {
            return e(Str::words(str_replace(self::STOP, '', $raw), self::WORDS, ' …'));
        }

        return str_replace([self::START, self::STOP], ['<mark>', '</mark>'], e($raw));
    }
}
