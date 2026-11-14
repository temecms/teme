<?php

declare(strict_types=1);

namespace App\Domain\Cache\Jobs;

use App\Domain\Cache\CdnPurges;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * Sends the purges waiting for the CDN (CdnPurges::drain()), one drain
 * queued at a time. It never fails for waiting: it is released for as long
 * as the CDN's rate or the CDN itself says, and a minute after a failure;
 * teme:cache:prune queues it again whenever purges wait (D5).
 */
final class PurgeCdn implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Released while it must wait, however often: never failed for that. */
    public int $tries = 0;

    /** Seconds before it runs again when something it relies on, such as the database, fails. */
    public int $backoff = CdnPurges::RETRY;

    /** Seconds one queued drain keeps another from being queued beside it. */
    public int $uniqueFor = 3600;

    public function handle(CdnPurges $purges): void
    {
        $wait = $purges->drain();

        // A job released on the sync queue simply ends, and would keep its
        // unique lock for an hour: there it ends done, and the next change or
        // the minute's prune queues the next drain.
        if ($wait !== null && ! $this->job instanceof SyncJob) {
            $this->release($wait);
        }
    }
}
