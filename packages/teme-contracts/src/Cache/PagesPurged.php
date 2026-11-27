<?php

/**
 * The public pages a change purged from the page cache, and the CDN purge
 * queued for them.
 *
 * @package Teme
 * @since   1.7.0
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
 * own. Nothing is sent when the page cache is off. The tags are what a purge
 * is made of, so purge an outside cache by tag. The addresses are only the
 * cached pages core warms again, and are empty when warming is off, so never
 * rely on them being complete.
 *
 * @since 1.7.0
 */
#[HookContract(capability: 'content.read')]
final readonly class PagesPurged implements Notification
{
    /**
     * @param  list<string>  $tags  the cache tags the change purged, each once, sorted
     * @param  list<string>  $addresses  the public addresses the purge touched, as absolute URLs, where core knows them
     *
     * @since 1.7.0
     */
    public function __construct(
        public array $tags,
        public array $addresses,
    ) {}
}
