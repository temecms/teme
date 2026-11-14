<?php

declare(strict_types=1);

namespace App\Domain\Cache;

use RuntimeException;

/**
 * A CDN's answer when it takes no more purges for now, with the seconds to
 * wait before asking again: Cloudflare's 429 and its Retry-After.
 */
final class CdnBusy extends RuntimeException
{
    public function __construct(public readonly int $seconds)
    {
        parent::__construct("The CDN asks the page cache to wait {$seconds} seconds before it purges again.");
    }
}
