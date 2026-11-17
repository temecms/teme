<?php

declare(strict_types=1);

namespace Teme\Contracts\Insertions;

use InvalidArgumentException;

/**
 * One piece of markup a contributor wants drawn at a place on a public
 * page. Its key keeps a second copy out; the first, by plugin code order,
 * wins.
 *
 * @since 1.0.0
 */
final readonly class PageInsertion
{
    /**
     * @param  string  $key  as Insertion::KEY_PATTERN: lower-case letters, digits, dots, hyphens and underscores
     *
     * @since 1.0.0
     */
    public function __construct(
        public string $key,
        public PagePosition $at,
        public string $html,
    ) {
        if (preg_match(Insertion::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("A page insertion's key [{$key}] isn't one: lower-case letters, digits, dots, hyphens and underscores.");
        }
    }
}
