<?php

/**
 * One piece of markup a contributor wants drawn at a position inside an
 * article.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Insertions;

use InvalidArgumentException;

/**
 * One piece of markup a contributor wants drawn at a position inside an
 * article. Its key keeps a second copy out; the first by precedence wins.
 *
 * @since 1.0.0
 */
final readonly class Insertion
{
    /** What a key may be: lower-case letters, digits, dots, hyphens and underscores. @since 1.0.0 */
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,99}$/';

    /**
     * @since 1.0.0
     */
    public function __construct(
        public string $key,
        public Position $at,
        public string $html,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("An insertion's key [{$key}] isn't one: lower-case letters, digits, dots, hyphens and underscores.");
        }
    }
}
