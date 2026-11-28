<?php

/**
 * The queued purge a plugin job's notice waits for when the minute of its
 * whole-site purge is taken: it purges every tag that arrived meanwhile, once,
 * and opens the next minute. Dispatched by NotePluginPagesChanged after the
 * notice's transaction commits.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cache;

use App\Domain\Cache\Operations\Input\PurgePublicTagsInput;
use App\Domain\Cache\Operations\PurgePublicTags;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

final class PurgeDeferredPages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** The cache key that holds the current minute's whole-site purge. */
    public const string WINDOW = 'plugins.pages-changed.window';

    /** The cache key that holds the tags waiting for the next purge. */
    public const string PENDING = 'plugins.pages-changed.pending';

    public function handle(): void
    {
        $tags = Cache::pull(self::PENDING);

        if (! is_array($tags) || $tags === []) {
            return;
        }

        Cache::put(self::WINDOW, true, 60);
        app(PurgePublicTags::class)->execute(new PurgePublicTagsInput(array_values($tags)));
    }
}
