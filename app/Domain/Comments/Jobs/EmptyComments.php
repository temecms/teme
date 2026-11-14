<?php

declare(strict_types=1);

namespace App\Domain\Comments\Jobs;

use App\Domain\Comments\Emptying;
use App\Domain\Comments\Queries\EmptyingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The rest of an Empty spam or Empty trash press, on the queue (1.8c′'s D5):
 * as the person who pressed it, an explicit actor, since a worker holds no
 * one's session; with the list's cut-off, so what arrived since stays; a part
 * at a time, each well inside the worker's timeout, handing the rest on.
 */
final class EmptyComments implements ShouldQueue
{
    use Queueable;

    /** Comments one job deletes: about 18 s at the benchmark's 9.1 ms each, under half the worker's 60 s (I-884). */
    public const int PER_JOB = 2000;

    /** A job that fails leaves the rest for another press: never run half again. */
    public int $tries = 1;

    public function __construct(
        public readonly int $viewerId,
        public readonly string $view,
        public readonly ?string $itemId,
        public readonly ?string $search,
        public readonly string $cutoff,
        public readonly int $limit = self::PER_JOB,
    ) {}

    public function handle(Emptying $emptying): void
    {
        $result = $emptying->run(new EmptyingRequest($this->viewerId, $this->view, $this->itemId, $this->search, CarbonImmutable::parse($this->cutoff)), $this->limit);

        // The next part, as the same person and with the same cut-off; never
        // after a part that deleted nothing, so nothing goes round for ever.
        if ($result->more && $result->deleted > 0) {
            self::dispatch($this->viewerId, $this->view, $this->itemId, $this->search, $this->cutoff, $this->limit);
        }
    }
}
