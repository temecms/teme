<?php

/**
 * Wires the page cache (slice 1.7): its store, files, files the web server
 * serves itself, or Redis, chosen by `page_cache.driver` each time one is
 * asked for, so the config a test sets reaches it, and the record each
 * page's render keeps and the list each Operation tells, and whether the
 * Redis connection could be made, one per request or job, and the Status
 * checks for a folder not every process shares and for a CDN that refuses
 * purges.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Cache\CdnPurges;
use App\Domain\Cache\FilePageStore;
use App\Domain\Cache\PageStore;
use App\Domain\Cache\PublicChanges;
use App\Domain\Cache\RedisOutage;
use App\Domain\Cache\RedisPageStore;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cache\StaticPages;
use App\Domain\Status\Checks\PageCacheCdnPurges;
use App\Domain\Status\Checks\PageCacheFolderShared;
use App\Domain\Status\StatusChecks;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class PageCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RenderRecord::class);
        $this->app->scoped(PublicChanges::class);
        $this->app->scoped(RedisOutage::class);

        $this->app->bind(StaticPages::class, fn (Application $app): StaticPages => new StaticPages(
            (string) config('page_cache.stores.static.path'),
            $app->make(Filesystem::class),
            $app->make(CdnPurges::class),
        ));

        $this->app->bind(PageStore::class, fn (Application $app): PageStore => match ($driver = (string) config('page_cache.driver')) {
            'file' => new FilePageStore((string) config('page_cache.stores.file.path'), $app->make(Filesystem::class)),
            // The file store, and a plain copy of each page the web server may serve itself (slice 1.7d″, I-086).
            'static' => new FilePageStore((string) config('page_cache.stores.file.path'), $app->make(Filesystem::class), $app->make(StaticPages::class)),
            'redis' => new RedisPageStore($app->make(RedisFactory::class), (string) config('page_cache.stores.redis.connection'), (string) config('page_cache.stores.redis.prefix'), $app->make(RedisOutage::class)),
            default => throw new InvalidArgumentException("The page cache has no store called [{$driver}]."),
        });
    }

    public function boot(): void
    {
        $this->app->make(StatusChecks::class)->register(PageCacheFolderShared::class);
        $this->app->make(StatusChecks::class)->register(PageCacheCdnPurges::class);
    }
}
