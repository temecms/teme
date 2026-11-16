<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Linker;

use Teme\Contracts\Content\ArticleLinks;
use Teme\Contracts\Content\LinkAttributes;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * Asks for a rel value Teme doesn't write.
 */
final class LinkerBadValue implements Plugin
{
    public function register(Hooks $hooks): void
    {
        $hooks->on(ArticleLinks::class, fn (array $attributes, ArticleLinks $hook): array => [0 => new LinkAttributes(['me'])]);
    }
}
