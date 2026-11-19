<?php

/**
 * The Custom HTML card in a feed: nothing. It belongs in theme regions only, which no
 * feed carries, so this version exists only so every card has one.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\RenderContext;
use App\Domain\Feeds\FeedCardRenderer;

final readonly class PlainSiteCode implements FeedCardRenderer
{
    public function card(): string
    {
        return 'site-code';
    }

    public function html(array $attrs, RenderContext $context): string
    {
        return '';
    }
}
