<?php

/**
 * Warns administrators when the CDN in front of the site refused the page
 * cache's last purge, so changes can show late there, by as long as a page
 * lives (docs/architecture/page-cache.md). It reads one cached value, which
 * the drain notes for a day when a purge fails and forgets when one goes
 * through.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Cache\CdnPurges;
use App\Domain\Cache\PageCache;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Cache;

final readonly class PageCacheCdnPurges implements StatusCheck
{
    public function ability(): string
    {
        return 'settings.general.manage';
    }

    /**
     * @since 0.8.0-alpha.1
     */
    public function label(): string
    {
        return __('Page cache');
    }

    public function banner(): ?StatusBanner
    {
        if (! is_array(Cache::get(CdnPurges::FAILED_KEY))) {
            return null;
        }

        return new StatusBanner(
            'warning',
            sprintf(
                'Some changes can show up to %s late: the CDN refused the last request to clear changed pages. Check the CDN setting (PAGE_CACHE_CDN) and its keys; the log says why.',
                CarbonInterval::seconds(PageCache::lifetime())->cascade()->forHumans(['aUnit' => true, 'parts' => 2, 'join' => true]),
            ),
            null,
            null,
            60,
        );
    }
}
