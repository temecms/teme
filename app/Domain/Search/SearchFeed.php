<?php

/**
 * Subscribes core to the content hooks that change what a search engine
 * holds, once for each dispatcher: a publish sends the item, an unpublish,
 * trash or deletion drops it (docs/guides/developers/plugins/search-engine.md).
 * Each change is queued after commit, and only while an engine is chosen.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Search;

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Plugins\Slots\SlotProviders;
use App\Domain\Search\Jobs\SyncSearchEntry;
use Teme\Contracts\Content\ContentDeleted;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Content\ContentUnpublished;
use WeakMap;

final class SearchFeed
{
    /** Where the container keeps the dispatchers core listens on. */
    private const string LISTENING = 'search.content-listening';

    /**
     * Subscribes core to the content hooks, once for each dispatcher, whether or
     * not an engine is chosen yet: one chosen later is kept in step from then on.
     */
    public static function listen(HookDispatcher $dispatcher): void
    {
        $listening = app()->bound(self::LISTENING) ? app(self::LISTENING) : null;

        if (! $listening instanceof WeakMap) {
            $listening = new WeakMap;
            app()->instance(self::LISTENING, $listening);
        }

        if (isset($listening[$dispatcher])) {
            return;
        }

        $listening[$dispatcher] = true;

        $core = $dispatcher->subscriber(HookDispatcher::CORE);
        $core->on(ContentPublished::class, fn (ContentPublished $hook) => self::queue($hook->item->id, SyncSearchEntry::SYNC));
        $core->on(ContentUnpublished::class, fn (ContentUnpublished $hook) => self::queue($hook->item->id, SyncSearchEntry::SYNC));
        $core->on(ContentTrashed::class, fn (ContentTrashed $hook) => self::queue($hook->item->id, SyncSearchEntry::SYNC));
        $core->on(ContentDeleted::class, fn (ContentDeleted $hook) => self::queue($hook->id, SyncSearchEntry::SYNC));
    }

    /**
     * Queues the item's sync when an engine is chosen.
     */
    private static function queue(string $contentId, string $action): void
    {
        if (app(SlotProviders::class)->chosenFor(PluginSlots::SEARCH) === null) {
            return;
        }

        SyncSearchEntry::dispatch($contentId, $action);
    }
}
