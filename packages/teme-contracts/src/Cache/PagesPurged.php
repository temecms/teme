<?php

/**
 * The public pages a change purged from the page cache, and the CDN purge
 * queued for them.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Cache;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * The public pages a change purged from the page cache, and the CDN purge
 * queued for them. Sent once for each purge, after the change is saved and
 * never inside its transaction, so a plugin can purge an outside cache of its
 * own. The addresses are those core knows of: the cached pages the change
 * drew again, which can be none when the cache warms no pages.
 *
 * @since next
 */
#[HookContract(capability: 'content.read')]
final readonly class PagesPurged implements Notification
{
    /**
     * @param  list<string>  $tags  the cache tags the change purged, each once, sorted
     * @param  list<string>  $addresses  the public addresses the purge touched, as absolute URLs, where core knows them
     *
     * @since next
     */
    public function __construct(
        public array $tags,
        public array $addresses,
    ) {}
}
