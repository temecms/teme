<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Jobs;

use App\Domain\Deployment\Health\PendingMigrations;
use App\Domain\Deployment\ReleaseRebuilds;
use App\Domain\Support\Security\JobActor;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LogicException;

/**
 * Hands a release's rebuild to the job that does it, for the actor the
 * deploy named (I-3113), only on a worker whose code ships the queuing
 * release's newest migration. A worker still on the release before puts it
 * back for a minute, for up to a day, until one on the new release takes it.
 */
final class RunReleaseRebuild implements ShouldQueue
{
    use Queueable;

    /** How long a worker of an older release puts it back, in seconds. */
    public const int LATER = 60;

    /** Real failures keep their tries; waiting for a worker never spends one. */
    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $rebuild,
        public readonly string $releaseMigration,
        public readonly JobActor $actor,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addDay();
    }

    /**
     * @throws LogicException when the release that queued it doesn't know its rebuild
     */
    public function handle(ReleaseRebuilds $rebuilds, PendingMigrations $migrations): void
    {
        if (! $migrations->ships($this->releaseMigration)) {
            $this->release(self::LATER);

            return;
        }

        $job = $rebuilds->job($this->rebuild, $this->actor)
            ?? throw new LogicException("No rebuild is called {$this->rebuild} in the release that queued it.");

        dispatch($job);
    }
}
