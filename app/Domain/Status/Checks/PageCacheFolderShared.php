<?php

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Cache\FilePageStore;
use App\Domain\Cache\PageCache;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Cache;

/**
 * Warns administrators when a process that removes pages from the page
 * cache, such as the queue worker or the scheduler, reaches another folder
 * than the one the web server stores them in, so changes show late, by as
 * long as a page lives (docs/architecture/page-cache.md). It reads one cached value, which the
 * file store sets for a day each time that happens.
 */
final readonly class PageCacheFolderShared implements StatusCheck
{
    public function ability(): string
    {
        return 'settings.general.manage';
    }

    public function banner(): ?StatusBanner
    {
        $problem = Cache::get(FilePageStore::UNSHARED_KEY);

        if (! is_array($problem)) {
            return null;
        }

        return new StatusBanner(
            'warning',
            sprintf(
                'Some changes can show up to %s late: %s clears saved pages from a folder this web server doesn’t read. Every part of Teme on the server must share one folder (PAGE_CACHE_PATH).',
                CarbonInterval::seconds(PageCache::lifetime())->cascade()->forHumans(['aUnit' => true, 'parts' => 2, 'join' => true]),
                (string) ($problem['host'] ?? 'another server'),
            ),
            null,
            null,
            60,
        );
    }
}
