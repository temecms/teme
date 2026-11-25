<?php

/**
 * Swaps the outside pictures of the posts waiting for a page of files a
 * picture pass just brought (#716): up to SecondPassStep::WAITING_BATCH
 * posts a job, one save each, in import mode, then a purge of only those
 * posts' pages, so readers see the library's pictures page by page. It
 * carries the files' waiting keys only, and does nothing once its run has
 * stopped or moved on.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Jobs;

use App\Domain\Cache\PublicChanges;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Phases\SecondPass\SecondPassStep;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Support\Security\JobActor;
use App\Domain\Users\Actors;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SwapPictures implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** Under the database queue's retry_after of 90 seconds. */
    public int $timeout = 75;

    /**
     * @param  list<string>  $keys  the files' waiting keys, by ImageTrace::waitingKeys()
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $token,
        public readonly array $keys,
        public readonly string $after,
        public readonly JobActor $actor,
    ) {}

    public function handle(ImportRuns $runs, ImportMode $mode, SecondPassStep $pass, PublicChanges $changes, Actors $actors): void
    {
        $this->actor->bind();
        $run = ImportRun::query()->find($this->runId);

        if ($run === null || $run->state !== ImportRunState::Running || $run->phase_token !== $this->token) {
            return;
        }

        $context = $runs->context($run, $this->actor);

        if ($this->actor->userId !== null) {
            $actors->keep($this->actor->userId);
        }

        try {
            $next = $mode->during($this->runId, fn (): ?string => $pass->passWaiting($context, $this->keys, $this->after));
        } finally {
            $actors->forget();
        }

        // This page of posts, purged after its commits, outside import mode (#716).
        $changes->purge($mode->takeHeld());

        if ($next !== null) {
            $this->batch()?->add([new self($this->runId, $this->token, $this->keys, $next, $this->actor)]);
        }
    }
}
