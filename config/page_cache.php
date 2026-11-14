<?php

declare(strict_types=1);

/*
 * The full-page cache (slice 1.7, docs/architecture/page-cache.md), on
 * everywhere but a developer's machine (APP_ENV=local), where a theme's
 * Blade changes must show at once; PAGE_CACHE_ENABLED turns it on or off
 * whatever the environment. A stored page lives an hour at most by default
 * (`lifetime`, PAGE_CACHE_LIFETIME, PageCache::lifetime()), and every
 * Operation that changes what it shows purges it after commit.
 *
 * `driver` names the store: `file` keeps each page in a file under
 * `stores.file.path`, found through the `page_cache_tags` table. That folder
 * must be one every process shares: the web server, which stores pages,
 * and the queue worker and the scheduler, whose Operations purge them. On
 * a folder of its own, a process removes a page's rows but not its file,
 * which is served until it expires; the Status banner says so. Its default,
 * `storage/page-cache`, sits outside `storage/framework`, which a container
 * may keep on a tmpfs of its own (docs/studies/deployment.md §3.2). `redis`
 * keeps pages in Valkey (slice 1.7d), which every process on every server
 * reaches, through the connection `stores.redis.connection` names in
 * config/database.php; it needs the phpredis extension, and no folder is
 * shared.
 * `static` is the file store, and a plain copy of each page the web server
 * may serve itself, with no PHP at all (slice 1.7d″, I-086): off unless
 * named here, and only with the web server's settings from docker/ in
 * place (docs/architecture/page-cache.md, "The static-file driver").
 */
return [
    'enabled' => (bool) env('PAGE_CACHE_ENABLED', env('APP_ENV', 'production') !== 'local'),

    // Seconds a stored page lives at most (PageCache::lifetime()): a purge
    // removes it sooner when what it shows changes, and a change that comes
    // by the clock caps it (ClockChanges, slice 1.7c).
    'lifetime' => (int) env('PAGE_CACHE_LIFETIME', 3600),

    'driver' => env('PAGE_CACHE_DRIVER', 'file'),

    'stores' => [
        'file' => [
            'path' => env('PAGE_CACHE_PATH', storage_path('page-cache')),
        ],

        'static' => [
            // The folder the web server serves pages from as plain files,
            // with the static-file driver: inside the file store's, so one
            // volume holds both and one shared-folder check covers both. The
            // web server's settings in docker/ name the same folder.
            'path' => env('PAGE_CACHE_STATIC_PATH', storage_path('page-cache/static')),
        ],

        'redis' => [
            // A connection in config/database.php's `redis` section, and the
            // prefix of every key the store writes, after the connection's own.
            'connection' => env('PAGE_CACHE_REDIS_CONNECTION', 'page-cache'),
            'prefix' => env('PAGE_CACHE_REDIS_PREFIX', 'page-cache:'),
        ],
    ],

    // The CDN in front of the site that Teme purges (slice 1.7d′), or none.
    // With one, a stored page tells it, in headers of its own that it strips
    // before the reader, the page's tags (hashed) and the life it has left,
    // and every change Teme purges is purged there too, on the queue.
    // Cache-Control still tells any other shared cache a minute at most.
    'cdn' => [
        'driver' => env('PAGE_CACHE_CDN'),

        'cloudflare' => [
            'zone' => env('CLOUDFLARE_ZONE_ID'),
            // An API token whose one permission is Zone > Cache Purge > Purge.
            'token' => env('CLOUDFLARE_API_TOKEN'),
            // Purge requests a minute: the Free plan's five. Pro takes five a
            // second and Business ten (developers.cloudflare.com/cache/how-to/purge-cache/).
            'purges_per_minute' => (int) env('CLOUDFLARE_PURGES_PER_MINUTE', 5),
        ],
    ],

    // Pages drawn again on the queue after a change (slice 1.7d′, study R
    // §3.11): up to `limit` of those it removed or set aside, never a search
    // or a list's later page, before the CDN is purged. Never on the sync
    // queue, where the request that made the change would draw them.
    'warm' => [
        'enabled' => (bool) env('PAGE_CACHE_WARM', true),
        'limit' => (int) env('PAGE_CACHE_WARM_PAGES', 20),
    ],
];
