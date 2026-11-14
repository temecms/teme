<?php

declare(strict_types=1);

namespace App\Domain\Site\Document;

use InvalidArgumentException;

/**
 * A piece of markup for one placement, written into the page as given: an
 * administrator's site code (I-288) or core's own. Its key keeps a second
 * copy out, the first by precedence winning.
 */
final readonly class Fragment
{
    /** What a key may be: lower-case letters, digits, dots, hyphens and underscores. */
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,99}$/';

    public function __construct(public string $key, public string $html)
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("A fragment's key [{$key}] isn't one: lower-case letters, digits, dots, hyphens and underscores.");
        }
    }
}
