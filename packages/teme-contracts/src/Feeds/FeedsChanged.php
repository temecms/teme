<?php

declare(strict_types=1);

namespace Teme\Contracts\Feeds;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * Feeds a change touched: the main feeds, a type's, a term's or an
 * author's, as the public sees them. Sent after the change is saved, only
 * when a plugin listens, never during an import run. It carries only
 * public addresses, so it needs no capability.
 *
 * @since 0.9.0
 */
#[HookContract]
final readonly class FeedsChanged implements Notification
{
    /** The most addresses one notification carries; beyond it, the main feeds and $all. */
    public const int MOST = 200;

    /**
     * @param  list<string>  $urls  the feeds' absolute addresses, each once, at most 200
     * @param  bool  $all  true when the change may touch every feed, such as the site's name
     *
     * @since 0.9.0
     */
    public function __construct(
        public array $urls,
        public bool $all = false,
    ) {}
}
