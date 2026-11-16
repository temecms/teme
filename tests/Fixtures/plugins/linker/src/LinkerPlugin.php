<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Linker;

use Teme\Contracts\Content\ArticleLinks;
use Teme\Contracts\Content\LinkAttributes;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * Marks every link nofollow and opens it in a new tab, asking for no host:
 * Teme keeps it for the links that lead outside the site.
 */
final class LinkerPlugin implements Plugin
{
    public function register(Hooks $hooks): void
    {
        $hooks->on(ArticleLinks::class, fn (array $attributes, ArticleLinks $hook): array => array_map(
            fn (): LinkAttributes => new LinkAttributes(['nofollow'], newTab: true, classes: ['external-link']),
            $hook->links,
        ));
    }
}
