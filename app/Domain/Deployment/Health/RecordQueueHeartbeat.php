<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * A queue worker's heartbeat (I-3112): after each job it finishes, done or
 * failed, the queue it took the job from has a worker. A job run without
 * one (sync, deferred or in the background) proves no worker runs.
 */
final readonly class RecordQueueHeartbeat
{
    public function __construct(private Heartbeats $heartbeats) {}

    public function handle(JobProcessed|JobExceptionOccurred $event): void
    {
        if ($event->job instanceof SyncJob) {
            return;
        }

        $this->heartbeats->beat(Heartbeats::QUEUE_PREFIX.$event->job->getQueue());
    }
}
