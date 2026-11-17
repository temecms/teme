<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Linker;

use Teme\Contracts\Content\ArticleLinks;
use Teme\Contracts\Content\LinkAttributes;
use Teme\Contracts\Content\OutboundLink;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * Marks every link sponsored, naming each link's host, so links to the site
 * are changed too.
 */
final class LinkerOwnHost implements Plugin
{
    public function register(Hooks $hooks): void
    {
        $hooks->on(ArticleLinks::class, fn (array $attributes, ArticleLinks $hook): array => array_map(
            fn (OutboundLink $link): LinkAttributes => new LinkAttributes(['sponsored'], host: $link->host),
            $hook->links,
        ));
    }
}
