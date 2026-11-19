<?php

/**
 * The plain version of one card in a feed (I-861): HTML a feed reader can
 * show with no script and no theme style. Kept in the feeds domain, beside
 * the card rather than in it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds;

use App\Domain\Cards\RenderContext;

interface FeedCardRenderer
{
    /**
     * The name of the card this is the version of, such as `callout`.
     */
    public function card(): string;

    /**
     * The card's HTML for a feed, or '' when it has nothing to show. The
     * attributes carry their declared defaults; every address in the result
     * is absolute, and every value is escaped.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function html(array $attrs, RenderContext $context): string;
}
