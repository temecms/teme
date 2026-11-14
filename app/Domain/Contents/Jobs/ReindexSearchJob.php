<?php

declare(strict_types=1);

namespace App\Domain\Contents\Jobs;

use App\Domain\Contents\Operations\Input\ReindexSearchInput;
use App\Domain\Contents\Operations\ReindexSearch;
use App\Domain\Support\Security\JobActor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Rebuilds search after the site language changes (I-435), one batch of
 * ReindexSearch a run, queueing the next until none remain. It carries whoever
 * changed the language, an administrator or the operator (I-392), whom the
 * Operation authorizes. The count still to go is kept in the cache for the Status
 * banner, and forgotten when the rebuild is done.
 */
final class ReindexSearchJob implements ShouldQueue
{
    use Queueable;

    /** The cache key the Status banner reads: the items still to rebuild. */
    public const string PROGRESS = 'search.reindex';

    public function __construct(public readonly JobActor $actor) {}

    public function handle(): void
    {
        $this->actor->bind();

        $result = app(ReindexSearch::class)->execute(new ReindexSearchInput($this->actor->userId));

        if ($result['remaining'] === 0) {
            Cache::forget(self::PROGRESS);

            return;
        }

        Cache::put(self::PROGRESS, $result['remaining'], now()->addDay());
        dispatch(new self($this->actor));
    }
}
