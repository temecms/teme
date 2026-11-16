<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

use InvalidArgumentException;

/**
 * What a plugin asks Teme to write on one link an article draws: rel values
 * from a closed list, whether it opens in a new tab, and at most two class
 * names the theme declares for links. Teme writes the attributes; a plugin
 * never writes markup. A link to this site is changed only when $host names
 * its host.
 *
 * @since 0.9.0
 */
final readonly class LinkAttributes
{
    /**
     * The rel values a plugin may ask for.
     *
     * @since 0.9.0
     */
    public const array REL = ['nofollow', 'sponsored', 'ugc', 'noopener', 'noreferrer'];

    /**
     * At most this many class names.
     *
     * @since 0.9.0
     */
    public const int MOST_CLASSES = 2;

    /**
     * @param  list<string>  $rel  values from REL
     * @param  bool|null  $newTab  true opens it in a new tab, false never; null leaves it as stored
     * @param  list<string>  $classes  at most MOST_CLASSES of the theme's declared link classes
     * @param  string|null  $host  the host this asks for: needed to change a link to this site
     *
     * @throws InvalidArgumentException for a rel value outside REL, or classes beyond MOST_CLASSES
     *
     * @since 0.9.0
     */
    public function __construct(
        public array $rel = [],
        public ?bool $newTab = null,
        public array $classes = [],
        public ?string $host = null,
    ) {
        foreach ($rel as $value) {
            if (! in_array($value, self::REL, true)) {
                throw new InvalidArgumentException('rel "'.$value.'" isn’t one Teme writes: '.implode(', ', self::REL).'.');
            }
        }

        if (count($classes) > self::MOST_CLASSES) {
            throw new InvalidArgumentException('A link takes at most '.self::MOST_CLASSES.' class names.');
        }
    }
}
