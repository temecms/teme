<?php

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Tells administrators of a production site when its scheduler has been
 * silent five minutes, or has never run (I-3112): scheduled posts,
 * backups and every other timed job wait on it, and only this banner can
 * say so.
 */
final readonly class SchedulerRunning implements StatusCheck
{
    /** Seconds since the last beat at which the scheduler counts as stopped. */
    public const int SILENT_AFTER = 300;

    /** Above every other banner: a stopped scheduler is why others follow. */
    public const int PRIORITY = 110;

    public function __construct(private Heartbeats $heartbeats) {}

    public function ability(): string
    {
        return 'settings.general.manage';
    }

    public function banner(): ?StatusBanner
    {
        // Outside production nobody runs it as a service; the doctor reports it there.
        if (! app()->isProduction()) {
            return null;
        }

        try {
            $last = $this->heartbeats->lastBeat(Heartbeats::SCHEDULER);
        } catch (QueryException) {
            // No table yet: code ahead of its migration, which /up reports (I-378).
            // StatusChecks catches nothing, so a throw here would fail every admin page.
            return null;
        }

        if ($last !== null && $last->greaterThan(CarbonImmutable::now()->subSeconds(self::SILENT_AFTER))) {
            return null;
        }

        $message = $last === null
            ? "Scheduled posts aren't publishing: the scheduler hasn't run on this site yet, so backups and other timed work wait too. Whoever runs the server should start it."
            : "Scheduled posts aren't publishing: the scheduler last ran {$last->diffForHumans()}, so backups and other timed work have stopped too. Whoever runs the server should start it.";

        return new StatusBanner('error', $message, null, null, self::PRIORITY);
    }
}
