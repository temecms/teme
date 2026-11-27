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

    public function __construct(
        public ?string $contentId,
        public string $action,
    ) {}

    public function handle(SearchEngineSync $sync): void
    {
        if ($this->action === self::REINDEX) {
            $sync->reindex();

            return;
        }

        if (is_string($this->contentId)) {
            $sync->sync($this->contentId);
        }
    }
}
