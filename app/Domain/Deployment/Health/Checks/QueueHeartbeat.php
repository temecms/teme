<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Deployment\Health\Severity;
use App\Domain\Deployment\Health\UntakenJobs;
use Carbon\CarbonInterface;

/**
 * Says which queues have jobs no worker is taking, and when a worker last
 * finished a job on each other queue (I-3112), in every environment. Always
 * a warning, so a first deploy, which runs before the workers exist, isn't
 * refused.
 */
final readonly class QueueHeartbeat implements DoctorCheck
{
    public function __construct(private UntakenJobs $jobs, private Heartbeats $heartbeats) {}

    public function title(): string
    {
        return 'Queue workers';
    }

    public function examine(): array
    {
        $untaken = $this->jobs->find();
        $findings = [];

        foreach ($untaken as $queue => $since) {
            $findings[] = new Finding(
                Severity::Warning,
                "Jobs on the {$queue} queue have waited {$since->diffForHumans(null, CarbonInterface::DIFF_ABSOLUTE)} with no worker taking them.",
                "Start a worker that reads it: php artisan queue:work with --queue={$queue}.",
            );
        }

        foreach ($this->heartbeats->queues() as $queue => $last) {
            if (! array_key_exists($queue, $untaken)) {
                $findings[] = new Finding(Severity::Ok, "A worker last finished a job on the {$queue} queue {$last->diffForHumans()}.");
            }
        }

        return $findings !== [] ? $findings : [new Finding(Severity::Info, 'No worker has finished a job yet, and none is waiting.')];
    }
}
