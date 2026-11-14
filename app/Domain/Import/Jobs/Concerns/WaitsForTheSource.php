<?php

declare(strict_types=1);

namespace App\Domain\Import\Jobs\Concerns;

use App\Domain\Import\Source\SourceRefused;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * How a job of a run waits out the old site not answering (#255): it goes
 * back on the queue for a longer wait each time, about an hour in all, and
 * fails with the refusal once that is used up. A job using it sets $tries to
 * one more than its waits, and $maxExceptions to 3, so any other error still
 * fails it after three.
 */
trait WaitsForTheSource
{
    /** The seconds the job waits after each try the old site didn't answer, by try: 3,810 in all. */
    private const array WAITS = [30, 60, 120, 300, 300, 600, 600, 900, 900];

    /**
     * Puts the job back on the queue to try again after its next wait; once
     * every wait is used up, or on a queue with no later, the refusal fails it.
     *
     * @throws SourceRefused
     */
    private function waitForSource(SourceRefused $refusal): void
    {
        $wait = self::WAITS[$this->attempts() - 1] ?? null;

        if ($wait === null || $this->job instanceof SyncJob) {
            throw $refusal;
        }

        $this->release($wait);
    }
}
