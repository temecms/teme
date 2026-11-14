<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Deployment\Health\Severity;
use App\Domain\Status\Checks\SchedulerRunning;
use Carbon\CarbonImmutable;

/**
 * Says when the scheduler last ran (I-3112), in every environment: the
 * Status banner shows only in production, but a developer or a deploy log
 * should learn of a scheduler that isn't running too. Always a warning,
 * so a first deploy, which runs before the scheduler exists, isn't refused.
 */
final readonly class SchedulerHeartbeat implements DoctorCheck
{
    private const string FIX = 'Start the scheduler role, which runs php artisan schedule:work.';

    public function __construct(private Heartbeats $heartbeats) {}

    public function title(): string
    {
        return 'Scheduler';
    }

    public function examine(): array
    {
        $last = $this->heartbeats->lastBeat(Heartbeats::SCHEDULER);

        if ($last === null) {
            return [new Finding(Severity::Warning, "The scheduler hasn't run on this site yet: scheduled posts, backups and other timed work wait until it runs.", self::FIX)];
        }

        if ($last->lessThanOrEqualTo(CarbonImmutable::now()->subSeconds(SchedulerRunning::SILENT_AFTER))) {
            return [new Finding(Severity::Warning, "The scheduler last ran {$last->diffForHumans()}: scheduled posts, backups and other timed work wait until it runs.", self::FIX)];
        }

        return [new Finding(Severity::Ok, "The scheduler last ran {$last->diffForHumans()}.")];
    }
}
