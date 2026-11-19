<?php

/**
 * `teme:cache:prune`: removes the pages whose life has ended, found through
 * the tag table's expiry index, so the files of addresses nobody asks for
 * again never pile up, and forgets purges older than any page still being
 * drawn (I-264). Runs every minute, as often as pages expire. While the
 * cache is off it removes every page, so turning it on again starts afresh
 * (I-475), and purges the whole site at the CDN the first time it removes
 * any. It also queues the drain of whatever purges wait for the CDN, and,
 * while the static-file driver is off, removes every file it left (slice
 * 1.7d″).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cache\CdnPurges;
use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageStore;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\PurgeTimes;
use App\Domain\Cache\StaticPages;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class PrunePageCache extends Command
{
    /** @var string */
    protected $signature = 'teme:cache:prune';

    /** @var string */
    protected $description = 'Remove the pages the page cache holds past their life';

    public function handle(PageStore $store, PurgeTimes $purges, CdnPurges $cdn, StaticPages $files): int
    {
        $purges->prune(now()->subSeconds(PageCache::RENDER_WINDOW));

        // Files the static-file driver left would be served for good once it
        // is off, the web server's settings still in place: nothing else
        // removes them. A CDN may keep an answer given from one, so the first
        // retire that removes any purges the whole site there (D12). Idle,
        // one indexed statement.
        if (config('page_cache.driver') !== 'static' && $files->retire() > 0) {
            $cdn->queue([PageTags::EVERYTHING]);
        }

        // Off, nothing purges a page as what it shows changes, so a page kept
        // from before would be served out of date the moment the cache is on
        // again (I-475): while it's off, nothing is kept.
        if (! PageCache::enabled()) {
            $removed = $store->flush();

            // Nor does anything purge the CDN, which may keep a page for its
            // whole life: the first flush that removes anything purges the
            // whole site there too (D12).
            if ($removed > 0) {
                $cdn->queue([PageTags::EVERYTHING]);
            }

            $cdn->kick();
            $this->components->info("The page cache is off: removed {$removed} ".Str::plural('page', $removed).' it held.');

            return self::SUCCESS;
        }

        $removed = $store->prune(now()->getTimestamp());

        // Whatever waits for the CDN is sent, however the last drain ended (D5).
        $cdn->kick();

        $this->components->info("Removed {$removed} expired ".Str::plural('page', $removed).' from the page cache.');

        return self::SUCCESS;
    }
}
