<?php

declare(strict_types=1);

namespace App\Domain\Cache;

use App\Domain\Cache\Jobs\PurgeCdn;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Throwable;

/**
 * The CDN in front of the site, when configuration names one
 * (`page_cache.cdn.driver`), and the purges waiting for it (study R §3.10):
 * once Teme's own pages are gone, a change's tags wait in
 * `page_cache_cdn_purges`, and the addresses of the files the web server
 * served and no longer has in `page_cache_cdn_addresses` (slice 1.7d″), each
 * numbered by PostgreSQL, and one drain at a time sends them, oldest first,
 * a batch a request: tags first, within the rate the CDN allows them, and
 * addresses within a budget of their own. The CDN knows each tag by a short
 * hash, never as written.
 */
final readonly class CdnPurges
{
    /** Where a refused purge is noted for a day, for the Status banner (PageCacheCdnPurges). */
    public const string FAILED_KEY = 'page-cache.cdn-failed';

    /** Seconds before a purge the CDN refused is tried again. */
    public const int RETRY = 60;

    /** The lock one drain holds, so two never send the same batch. */
    private const string LOCK = 'page-cache.cdn';

    /** The rate limiter's count of this minute's requests to the CDN by tag or by host. */
    private const string RATE = 'page-cache.cdn-rate';

    /** The rate limiter's count of this second's requests to the CDN by address (D10). */
    private const string ADDRESS_RATE = 'page-cache.cdn-address-rate';

    /** Seconds before a drain that found another at work looks again. */
    private const int BUSY = 10;

    /** Requests one drain sends before it makes way for the queue's other jobs. */
    private const int REQUESTS_PER_RUN = 10;

    /**
     * Seconds of real time after which a drain sends no more: with one
     * request's worst (five to connect, fifteen to answer), a run stays
     * inside the worker's 60-second timeout however slow the CDN is.
     */
    private const int RUN_SECONDS = 30;

    /** Rows written per statement. */
    private const int BATCH = 500;

    /**
     * `page_cache_cdn_purges`' one column a row gives: a row written as an
     * array keyed by the column's own name reads, to NoTypeLiteralsTest, as a
     * type named in code (B-225).
     */
    private const array COLUMNS = ['tag'];

    /** `page_cache_cdn_addresses`' one column a row gives, written as COLUMNS' rows are. */
    private const array ADDRESS_COLUMNS = ['path'];

    /**
     * The CDN configuration names, or null when it names none. Read at each
     * call, so the configuration a test sets reaches it. A name no driver
     * has, or Cloudflare without its zone or token, throws, as an unknown
     * page store does: a deploy-time mistake shows at once (D15).
     */
    public function driver(): ?CdnDriver
    {
        return match ($name = (string) config('page_cache.cdn.driver')) {
            '' => null,
            'cloudflare' => new CloudflareCdn(
                (string) config('page_cache.cdn.cloudflare.zone'),
                (string) config('page_cache.cdn.cloudflare.token'),
                (int) config('page_cache.cdn.cloudflare.purges_per_minute'),
                strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            ),
            default => throw new InvalidArgumentException("The page cache has no CDN driver called [{$name}]."),
        };
    }

    /**
     * The keys the CDN knows these tags by, in their order: 16 hex characters
     * of each tag's xxh3. Short and plain ASCII whatever a tag holds, and
     * never an ID a reader could read: a content ID is a ULID, whose first
     * part says when its draft was made (I-496). A collision purges a page too
     * many, never one too few (D2).
     *
     * @param  list<string>  $tags
     *
     * @return list<string>
     */
    public static function keys(array $tags): array
    {
        return array_map(fn (string $tag): string => hash('xxh3', $tag), $tags);
    }

    /**
     * Notes these tags for the CDN, each row numbered by PostgreSQL as it is
     * written; nothing without a CDN. `*` stands for the whole site. Called
     * once Teme's own pages carrying them are gone, after the change's commit.
     *
     * @param  list<string>  $tags
     */
    public function queue(array $tags): void
    {
        if ($tags === [] || $this->driver() === null) {
            return;
        }

        foreach (array_chunk(array_values(array_unique($tags)), self::BATCH) as $chunk) {
            DB::table('page_cache_cdn_purges')->insert(
                array_map(fn (string $tag): array => array_combine(self::COLUMNS, [$tag]), $chunk),
            );
        }
    }

    /**
     * Notes, for the CDN, the paths of the files the web server served and no
     * longer has, each row numbered by PostgreSQL as it is written; nothing
     * without a CDN. An answer given from a file carries no Cache-Tag, so the
     * CDN is purged by its address (I-514, D10).
     *
     * @param  list<string>  $paths
     */
    public function queueAddresses(array $paths): void
    {
        if ($paths === [] || $this->driver() === null) {
            return;
        }

        foreach (array_chunk(array_values(array_unique($paths)), self::BATCH) as $chunk) {
            DB::table('page_cache_cdn_addresses')->insert(
                array_map(fn (string $path): array => array_combine(self::ADDRESS_COLUMNS, [$path]), $chunk),
            );
        }
    }

    /**
     * Queues the drain when a CDN is configured and purges, by tag or by
     * address, wait for it; one drain is queued at a time (PurgeCdn is unique).
     */
    public function kick(): void
    {
        if ($this->driver() !== null && (DB::table('page_cache_cdn_purges')->exists() || DB::table('page_cache_cdn_addresses')->exists())) {
            PurgeCdn::dispatch();
        }
    }

    /**
     * Sends what waits, oldest first, a batch a request, and returns how many
     * seconds to wait before sending the rest, or null when nothing is left
     * (1.7d′'s D4, D5). Tags go first, whenever their rate allows; addresses
     * go whenever it doesn't, within their own budget (D10). It waits when
     * both budgets are spent for what waits, or the CDN says to; a purge the
     * CDN refuses is reported, noted for the Status banner, and tried again
     * after RETRY, its rows kept. A row queued while its batch is out is
     * numbered after the batch, and waits for the next request: its change is
     * newer than the purge.
     */
    public function drain(): ?int
    {
        $driver = $this->driver();

        if ($driver === null) {
            // The CDN was switched off: what waited has nowhere to go.
            DB::table('page_cache_cdn_purges')->delete();
            DB::table('page_cache_cdn_addresses')->delete();

            return null;
        }

        $lock = Cache::lock(self::LOCK, 120);

        if (! $lock->get()) {
            return self::BUSY;
        }

        // Real time, which travel() doesn't move: a frozen clock can't keep a drain sending.
        $started = microtime(true);

        try {
            for ($sent = 0; $sent < self::REQUESTS_PER_RUN; $sent++) {
                if ($sent > 0 && microtime(true) - $started > self::RUN_SECONDS) {
                    return 0;
                }

                $upTo = (int) DB::table('page_cache_cdn_purges')->max('id');
                $addressesUpTo = (int) DB::table('page_cache_cdn_addresses')->max('id');

                if ($upTo === 0 && $addressesUpTo === 0) {
                    return null;
                }

                $tagsSpent = $upTo > 0 && RateLimiter::tooManyAttempts(self::RATE, max(1, $driver->perMinute()));
                $addressesSpent = $addressesUpTo > 0 && RateLimiter::tooManyAttempts(self::ADDRESS_RATE, self::addressRequestsPerSecond($driver));

                $wait = match (true) {
                    $upTo > 0 && ! $tagsSpent => $this->sendTags($driver, $upTo, $addressesUpTo),
                    $addressesUpTo > 0 && ! $addressesSpent => $this->sendAddresses($driver, $addressesUpTo),
                    // Every budget spent for what waits: the sooner one to come back.
                    default => max(1, min(
                        $tagsSpent ? RateLimiter::availableIn(self::RATE) : PHP_INT_MAX,
                        $addressesSpent ? RateLimiter::availableIn(self::ADDRESS_RATE) : PHP_INT_MAX,
                    )),
                };

                if ($wait !== null) {
                    return $wait;
                }
            }

            // More may wait: the queue runs its other jobs, then this drain again.
            return 0;
        } finally {
            $lock->release();
        }
    }

    /**
     * Sends the oldest batch of tags queued at or before $upTo, or the whole
     * site when `*` is among them, which also takes every address queued at
     * or before $addressesUpTo. Returns the seconds to wait when the CDN
     * didn't take it, or null.
     */
    private function sendTags(CdnDriver $driver, int $upTo, int $addressesUpTo): ?int
    {
        $everything = DB::table('page_cache_cdn_purges')->where('id', '<=', $upTo)->where('tag', PageTags::EVERYTHING)->exists();
        $tags = $everything ? [] : $this->oldest($upTo, $driver->batchSize());

        if (! $everything && $tags === []) {
            return null;
        }

        RateLimiter::hit(self::RATE, 60);

        $wait = $this->send(function () use ($driver, $everything, $tags): void {
            if ($everything) {
                $driver->purgeSite();
            } else {
                $driver->purgeKeys(self::keys($tags));
            }
        });

        if ($wait !== null) {
            return $wait;
        }

        $this->sent($upTo, $everything ? null : $tags);

        // The whole site covers every address queued before it.
        if ($everything) {
            DB::table('page_cache_cdn_addresses')->where('id', '<=', $addressesUpTo)->delete();
        }

        return null;
    }

    /**
     * Sends the oldest batch of addresses queued at or before $upTo, as full
     * URLs at the site's own scheme and host, and forgets them once the CDN
     * takes them. Returns the seconds to wait when it didn't, or null.
     */
    private function sendAddresses(CdnDriver $driver, int $upTo): ?int
    {
        $paths = DB::table('page_cache_cdn_addresses')
            ->where('id', '<=', $upTo)
            ->groupBy('path')
            ->orderByRaw('min(id)')
            ->limit($driver->batchSize())
            ->pluck('path')
            ->map(fn (mixed $path): string => (string) $path)
            ->values()
            ->all();

        if ($paths === []) {
            return null;
        }

        RateLimiter::hit(self::ADDRESS_RATE, 1);
        $origin = StaticPages::origin();

        $wait = $this->send(function () use ($driver, $origin, $paths): void {
            $driver->purgeUrls(array_map(fn (string $path): string => $origin.$path, $paths));
        });

        if ($wait !== null) {
            return $wait;
        }

        DB::table('page_cache_cdn_addresses')->where('id', '<=', $upTo)->whereIn('path', $paths)->delete();

        return null;
    }

    /**
     * Sends one purge request, and returns the seconds to wait when the CDN
     * didn't take it: as long as it says, or RETRY after a failure, which is
     * reported and noted for the Status banner. Null when it took it.
     *
     * @param  Closure(): void  $request
     */
    private function send(Closure $request): ?int
    {
        try {
            $request();
        } catch (CdnBusy $busy) {
            return $busy->seconds;
        } catch (Throwable $failure) {
            report($failure);
            Cache::put(self::FAILED_KEY, ['at' => now()->toIso8601String()], now()->addDay());

            return self::RETRY;
        }

        Cache::forget(self::FAILED_KEY);

        return null;
    }

    /**
     * Requests by address a second, within the CDN's budget for addresses.
     */
    private static function addressRequestsPerSecond(CdnDriver $driver): int
    {
        return max(1, intdiv($driver->addressesPerSecond(), max(1, $driver->batchSize())));
    }

    /**
     * Up to $size tags queued at or before $upTo, each once, oldest first.
     *
     * @return list<string>
     */
    private function oldest(int $upTo, int $size): array
    {
        return DB::table('page_cache_cdn_purges')
            ->where('id', '<=', $upTo)
            ->groupBy('tag')
            ->orderByRaw('min(id)')
            ->limit($size)
            ->pluck('tag')
            ->map(fn (mixed $tag): string => (string) $tag)
            ->values()
            ->all();
    }

    /**
     * Forgets what the CDN has just taken: these tags' rows numbered at or
     * before $upTo, or every such row when it took the whole site (null).
     *
     * @param  list<string>|null  $tags
     */
    private function sent(int $upTo, ?array $tags): void
    {
        $rows = DB::table('page_cache_cdn_purges')->where('id', '<=', $upTo);

        if ($tags !== null) {
            $rows->whereIn('tag', $tags);
        }

        $rows->delete();
    }
}
