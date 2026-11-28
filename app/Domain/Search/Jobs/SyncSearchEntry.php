<?php

/**
 * Sends one item to the chosen search engine after it changes, or every
 * published item on a reindex. Queued after each publish, unpublish or
 * deletion, so the engine is eventually consistent with the site.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Search\Jobs;

use App\Domain\Search\SearchEngineSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncSearchEntry implements ShouldQueue
{
    use Queueable;

    /** The action that sends every published item. */
    public const string REINDEX = 'reindex';

    /** The action that sends one item. */
    public const string SYNC = 'sync';

    /**
     * @param  string|null  $after  for a reindex, the last item of the chunk before this one
     */
    public function __construct(
        public ?string $contentId,
        public string $action,
        public ?string $after = null,
    ) {}

    public function handle(SearchEngineSync $sync): void
    {
        if ($this->action === self::REINDEX) {
            // One chunk now; a full chunk queues the next, so no job holds every item.
            $next = $sync->reindexChunk($this->after);

            if ($next !== null) {
                self::dispatch(null, self::REINDEX, $next);
            }

            return;
        }

        if (is_string($this->contentId)) {
            $sync->sync($this->contentId);
        }
    }
}
