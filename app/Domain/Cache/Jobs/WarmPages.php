<?php

/**
 * Draws again, on the queue, the pages a change removed or set aside
 * (docs/studies/page-cache.md §3.11), one at a time inside Teme, and only
 * then starts the CDN's purge: Teme regenerates first, then the CDN is
 * purged (§3.10), so the CDN's first fetch after its purge finds a stored
 * page (D8).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cache\Jobs;

use App\Domain\Cache\CdnPurges;
use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageWarmer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class WarmPages implements ShouldQueue
{
    use Queueable;

    /**
     * Seconds of real time after which it draws no more pages, so a slow
     * page never holds the worker past its 60-second timeout: the rest are
     * drawn by their next readers, and the CDN's purge still follows.
     */
    private const int SECONDS = 30;

    /** A page not drawn now is drawn by its next reader: never worth a second try. */
    public int $tries = 1;

    /**
     * @param  list<string>  $urls
     */
    public function __construct(public readonly array $urls) {}

    public function handle(PageWarmer $warmer, CdnPurges $cdn): void
    {
        $started = microtime(true);

        try {
            foreach ($this->urls as $url) {
                // Switched off meanwhile, nothing would be stored; out of time, the rest wait for readers.
                if (! PageCache::enabled() || microtime(true) - $started > self::SECONDS) {
                    break;
                }

                try {
                    $warmer->warm($url);
                } catch (Throwable $failure) {
                    report($failure);
                }
            }
        } finally {
            $cdn->kick();
        }
    }
}
