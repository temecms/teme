<?php

/**
 * The plugins that ran over a public page's search socket's time budget in
 * this request or job, by plugin and socket: SeoPlugins asks such a plugin
 * nothing more on that socket until the request ends, and records the
 * fault once. Only the sockets in ENFORCED: the editor's checks are asked
 * once a save. Scoped, so it is forgotten between requests and jobs.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

final class SlowSockets
{
    /** The sockets a page, a sitemap or an image asks: head tags, structured data, sitemaps and image addresses. */
    public const array ENFORCED = ['head-meta', 'structured-data', 'sitemaps', 'image-service'];

    /** @var array<string, true> */
    private array $over = [];

    /** Whether this plugin already ran over this socket's budget in this request. */
    public function isOver(string $plugin, string $socket): bool
    {
        return isset($this->over["{$plugin}#{$socket}"]);
    }

    /** Notes that this plugin ran over this socket's budget. */
    public function markOver(string $plugin, string $socket): void
    {
        if (! in_array($socket, self::ENFORCED, true)) {
            return;
        }

        $this->over["{$plugin}#{$socket}"] = true;
    }
}
