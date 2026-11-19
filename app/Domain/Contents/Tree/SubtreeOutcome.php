<?php

/**
 * What moving the pages under a page did (I-3363): how many moved in the
 * save, how many a queued job will move and its ID, the pages that kept
 * their old address because the new one is someone else's, and what the
 * page cache must set aside after commit.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Tree;

use Teme\Contracts\Cache\CacheKeysFor;

final readonly class SubtreeOutcome
{
    /**
     * @param  list<array{title: string, path: string, address: string, holder: string}>  $kept
     * @param  list<CacheKeysFor>  $purges
     */
    public function __construct(
        public int $moved = 0,
        public int $queued = 0,
        public ?string $jobId = null,
        public array $kept = [],
        public array $purges = [],
    ) {}

    /**
     * Whether nothing moved, nothing was queued and nothing was kept.
     */
    public function isEmpty(): bool
    {
        return $this->moved === 0 && $this->queued === 0 && $this->kept === [];
    }
}
