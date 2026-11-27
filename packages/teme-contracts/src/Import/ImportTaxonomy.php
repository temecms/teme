<?php

/**
 * The taxonomies a reader's terms may belong to, in Teme's words: categories
 * and tags, or a plugin's own taxonomy by its key as Teme has it.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Import;

/**
 * The taxonomy words a reader may give a term.
 *
 * @since next
 */
final class ImportTaxonomy
{
    /** @since next */
    public const string CATEGORY = 'category';

    /** @since next */
    public const string TAG = 'tag';

    /**
     * Whether a word has the shape of a taxonomy word a reader may give: `category`,
     * `tag`, or a key of 1 to 32 characters that starts with a lower-case letter and
     * then has only lower-case letters, digits, hyphens and underscores. A word of
     * that shape is still refused unless Teme has the taxonomy registered, which the
     * import checks when it reads the file.
     *
     * @since next
     */
    public static function valid(string $taxonomy): bool
    {
        return $taxonomy === self::CATEGORY || $taxonomy === self::TAG || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/', $taxonomy) === 1;
    }
}
