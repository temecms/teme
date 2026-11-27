<?php

/**
 * The theme's region a card is placed in: its slug, its theme, and the
 * size the theme gives it, if any.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Cards;

use Stringable;

/**
 * The theme's region a card is placed in. Its size is the theme's advice,
 * such as 300x600 for a sidebar ad: a card may read it, to list that
 * size's ads first, and core never refuses a card for it.
 *
 * @since 1.5.0
 */
final readonly class CardRegion implements Stringable
{
    /**
     * @param  string  $slug  the region's slug, as the theme's manifest declares it
     * @param  string  $theme  the theme's code
     * @param  string|null  $size  as the manifest gives it, "300x600"
     * @param  int|null  $width  the size's width in pixels
     * @param  int|null  $height  the size's height in pixels
     *
     * @since 1.5.0
     */
    public function __construct(
        public string $slug,
        public string $theme,
        public ?string $size = null,
        public ?int $width = null,
        public ?int $height = null,
    ) {}

    /**
     * The region's slug, so a view may print the region or compare it with a
     * name: `$region == 'sidebar'`. An empty string outside a region.
     *
     * @since 1.7.0
     */
    public function __toString(): string
    {
        return $this->slug;
    }
}
