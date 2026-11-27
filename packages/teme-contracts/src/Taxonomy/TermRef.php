<?php

/**
 * A category or tag as a hook tells of it: plain values read after the change
 * was saved, or, for a deletion, as they were before the row went.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Taxonomy;

/**
 * A category or tag as a hook tells of it: plain values, never the term
 * itself.
 *
 * @since 1.7.0
 */
final readonly class TermRef
{
    /**
     * @param  string  $id  the term's ID, a lower-case ULID
     * @param  string  $taxonomy  its taxonomy's key, such as category or tag
     * @param  string  $name  its name
     * @param  string  $slug  its slug
     * @param  string|null  $parentId  the ID of its parent term, or null when it has none
     * @param  string|null  $language  its language as a BCP 47 tag, or null when it has none
     *
     * @since 1.7.0
     */
    public function __construct(
        public string $id,
        public string $taxonomy,
        public string $name,
        public string $slug,
        public ?string $parentId,
        public ?string $language,
    ) {}
}
