<?php

/**
 * A plugin's card in a feed: nothing. It belongs in theme regions only, which
 * no feed carries, so this version exists only so every card has one.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\RenderContext;
use App\Domain\Feeds\FeedCardRenderer;

/**
 * A plugin's card in a feed: nothing, as for every card that goes in a
 * theme's region only.
 */
final readonly class PlainPluginCard implements FeedCardRenderer
{
    public function card(): string
    {
        return 'plugin-card';
    }

    public function html(array $attrs, RenderContext $context): string
    {
        return '';
    }
}
