<?php

declare(strict_types=1);

namespace App\Domain\Benchmark;

use App\Domain\Addresses\Addresses;
use App\Domain\Cache\Jobs\PurgeCdn;
use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageStore;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Redirects\AddressCounts;
use Closure;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * The page cache's scenarios for the benchmark (slice 1.7e): hits, misses, a
 * STALE answer, a wait and a change with the cache on, for the file store
 * and, when a Valkey answers, the Redis store; a change with a CDN and with
 * warming; a miss, a change and the prune with the static-file driver. Each
 * switches the cache on for itself, in a folder and a prefix of its own, and
 * leaves nothing behind (D7).
 */
final class CacheScenarios
{
    /** The benchmark's own prefix for the Redis store's keys. */
    private const string PREFIX = 'page-cache-bench:';

    /**
     * Cache stores whose writes stay with the benchmark: its own database,
     * where BenchmarkDatabase points the database store, or memory. The file
     * store tells the cache store its folder's name, and a store the site
     * shared would take the benchmark's folder for the site's own.
     */
    private const array CONTAINED = ['database', 'array'];

    /**
     * What the run noted beside its table.
     *
     * @var list<string>
     */
    private array $notes = [];

    /**
     * The page cache's configuration as it was, put back after each scenario.
     *
     * @var array<string, mixed>
     */
    private array $pageCache = [];

    /** The queue as it was, put back after each scenario. */
    private string $queue = 'sync';

    /** The application's environment as it was, put back after a static-file driver's scenario. */
    private ?string $environment = null;

    /** The benchmark's own folder, inside the file store's (D7). */
    private string $folder = '';

    /** The last queued job's ID when a scenario began: every job after it is the scenario's. */
    private int $jobsBefore = 0;

    /** The X-Teme-Cache-Tags the last answer carried. */
    private string $lastTags = '';

    /** The lock a scenario holds, as another request drawing its page would. */
    private ?Lock $held = null;

    public function __construct(private readonly Tally $tally) {}

    /**
     * Every cache scenario, in the runner's shape, the Redis store's only
     * when a Valkey answers (D10); none when the cache store isn't one the
     * benchmark keeps to itself, which the notes then say.
     *
     * @param  list<string>  $exclude  the posts other scenarios edit
     *
     * @return list<array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, prepare: Closure(): void, finish: Closure(): void, before?: Closure(int): void, readsNothing?: bool}>
     */
    public function all(int $runs, string $admin, array $exclude): array
    {
        if (! in_array(config('cache.default'), self::CONTAINED, true)) {
            $this->notes[] = sprintf('The page cache: not measured. Its file store tells the cache store its folder, which stays with the benchmark only in the database store or in memory; this run has [%s].', (string) config('cache.default'));

            return [];
        }

        $this->pageCache = (array) config('page_cache');
        $this->queue = (string) config('queue.default');
        $this->folder = rtrim((string) config('page_cache.stores.file.path'), '/\\').'/benchmark';

        $each = $runs + 2;
        $articles = $this->articles(1 + 3 * $each);
        $posts = array_chunk($this->editable(5 * $each, $exclude), $each);
        $category = Addresses::toUrl($this->addressOf('term', (string) DB::table('terms')->where('taxonomy', 'category')->orderByDesc('published_count')->orderBy('id')->value('id')));
        $author = Addresses::toUrl($this->addressOf('user', (string) DB::table('users')->where('email', 'author2@bench.test')->value('id')));
        $twentyTags = Addresses::toUrl($this->addressOf('content', 'C'.str_pad((string) BenchmarkSeeder::TWENTY_TAGS_ITEM, 25, '0', STR_PAD_LEFT)), 'post');

        $scenarios = [
            $this->hit('cache: hit, article', 'file', $articles[0]),
            $this->hit('cache: hit, front page', 'file', '/'),
            $this->hit('cache: hit, search, common word', 'file', '/?s='.BenchmarkSeeder::COMMON_WORD),
            $this->hit('cache: hit, article, 20 tags, with a CDN', 'file', $twentyTags, cdn: true),
            $this->miss('cache: miss, article', 'file', array_slice($articles, 1, $each)),
            $this->miss('cache: miss, not found', 'file', SeoScenarios::newAddresses($each), 404),
            $this->missAgain('cache: miss, front page', 'file', '/'),
            $this->stale('cache: stale, category page', 'file', $category),
            $this->wait('cache: wait, author page', 'file', $author),
            $this->change('cache: quick edit', 'file', $posts[0] ?? [], $admin),
            $this->change('cache: quick edit, with a CDN', 'file', $posts[1] ?? [], $admin, cdn: true),
            $this->change('cache: quick edit, with warming', 'file', $posts[2] ?? [], $admin, warm: true),
            $this->miss('cache: miss, article, static driver', 'static', array_slice($articles, 1 + $each, $each)),
            $this->change('cache: quick edit, static driver', 'static', $posts[3] ?? [], $admin),
            $this->prune('cache: prune, static driver'),
        ];

        if (! $this->valkeyAnswers()) {
            return $scenarios;
        }

        return [
            ...$scenarios,
            $this->hit('cache: hit, article (Redis)', 'redis', $articles[0]),
            $this->miss('cache: miss, article (Redis)', 'redis', array_slice($articles, 1 + 2 * $each, $each)),
            $this->stale('cache: stale, category page (Redis)', 'redis', $category),
            $this->wait('cache: wait, author page (Redis)', 'redis', $author),
            $this->change('cache: quick edit (Redis)', 'redis', $posts[4] ?? [], $admin),
        ];
    }

    /**
     * What the scenarios noted: each cached page's tags, and what couldn't
     * be measured, and why.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * A hit on the page at this address: the warm-up draws and stores it,
     * and every run after is answered from the store, reading nothing from
     * the database (D8). With a CDN, each answer also hashes the page's tags
     * for it.
     *
     * @return array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, prepare: Closure(): void, finish: Closure(): void, readsNothing: bool}
     */
    private function hit(string $name, string $driver, string $path, bool $cdn = false): array
    {
        return [
            'name' => $name,
            'group' => 'public-hit',
            'indexOrder' => false,
            'readsNothing' => true,
            'prepare' => function () use ($driver, $cdn): void {
                $this->on($driver, $cdn);
            },
            'run' => fn (int $i): string => $this->read($path, $i, $i === 0 ? null : 'HIT'),
            'finish' => function () use ($name, $path): void {
                try {
                    $this->noteTags($name, $path);
                } finally {
                    $this->off();
                }
            },
        ];
    }

    /**
     * A miss in every run: each asks for an article no run has asked for, so
     * it is drawn under its lock and stored, MISS. Its budget is the uncached
     * article's (I-400): a miss is a page drawn.
     *
     * @param  list<string>  $paths  one for each run
     *
     * @return array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, prepare: Closure(): void, finish: Closure(): void}
     */
    private function miss(string $name, string $driver, array $paths, int $status = 200): array
    {
        return [
            'name' => $name,
            'group' => 'public-item',
            'indexOrder' => false,
            'prepare' => function () use ($driver): void {
                $this->on($driver);
            },
            'run' => fn (int $i): string => $this->read($paths[$i] ?? throw new RuntimeException("{$name}: no address left for run {$i}."), $i, 'MISS', $status),
            'finish' => function (): void {
                $this->off();
            },
        ];
    }

    /**
     * A miss of the same page in every run, its copy removed before each,
     * outside the timing, as a change removes it. Its budget is the uncached
     * listing's (I-400).
     *
     * @return array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, prepare: Closure(): void, finish: Closure(): void, before: Closure(int): void}
     */
    private function missAgain(string $name, string $driver, string $path): array
    {
        return [
            'name' => $name,
            'group' => 'public-list',
            'indexOrder' => true,
            'prepare' => function () use ($driver): void {
                $this->on($driver);
            },
            'before' => function (int $i) use ($path): void {
                app(PageStore::class)->forget($this->keyOf($path)->url);
            },
            'run' => fn (int $i): string => $this->read($path, $i, 'MISS'),
            'finish' => function (): void {
                $this->off();
            },
        ];
    }

    /**
     * A STALE answer in every run: the page at this address is drawn and
     * stored, set aside as a changed list sets it aside, and its lock held,
     * as while another request draws it afresh; each run is refused the lock
     * and given the copy (B-332's refused lock, with the file store).
     *
     * @return array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, prepare: Closure(): void, finish: Closure(): void}
     */
    private function stale(string $name, string $driver, string $path): array
    {
        return [
            'name' => $name,
            'group' => 'public-hit',
            'indexOrder' => false,
            'prepare' => function () use ($driver, $path): void {
                $this->on($driver);
                $this->read($path, 0, 'MISS');
                $key = $this->keyOf($path);

                if (app(PageStore::class)->markStale([$key->urlTag()], now()->getTimestamp() + PageCache::RENDER_WINDOW) !== 1) {
                    throw new RuntimeException("{$path}: its page was not set aside.");
                }

                $this->hold($key);
            },
            'run' => fn (int $i): string => $this->read($path, $i, 'STALE'),
            'finish' => function (): void {
                $this->off();
            },
        ];
    }

    /**
     * A wait in every run: the page's lock is held and it has no copy to
     * give, so the request waits, and the page arrives at its first look, as
     * when the request holding the lock stores it (D9). Sleep is faked, so
     * the wait's step isn't slept; storing the page is another request's
     * work, which Tally::aside() leaves uncounted. Before each run, outside
     * the timing, the page is removed again.
     *
     * @return array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, prepare: Closure(): void, finish: Closure(): void, before: Closure(int): void}
     */
    private function wait(string $name, string $driver, string $path): array
    {
        return [
            'name' => $name,
            'group' => 'public-hit',
            'indexOrder' => false,
            'prepare' => function () use ($driver, $path): void {
                $this->on($driver);
                $this->read($path, 0, 'MISS');
                $key = $this->keyOf($path);
                $entry = app(PageStore::class)->get($key) ?? throw new RuntimeException("{$path}: its page was not stored.");
                $this->hold($key);

                Sleep::fake();
                Sleep::whenFakingSleep(function () use ($entry): void {
                    $this->tally->aside(function () use ($entry): void {
                        app(PageStore::class)->put($entry);
                    });
                });
            },
            'before' => function (int $i) use ($path): void {
                app(PageStore::class)->forget($this->keyOf($path)->url);
            },
            'run' => fn (int $i): string => $this->read($path, $i, 'HIT'),
            'finish' => function (): void {
                Sleep::fake(false);
                $this->off();
            },
        ];
    }

    /**
     * A quick edit of a published post with the cache on, one post a run: it
     * reads what the public saw before and sees after (its footprints), and
     * after its commit removes the post's page and sets the front page
     * aside; with a CDN it queues their purge and the drain; with warming it
     * reads what to draw again and queues it. Before each run, outside the
     * timing, the post's page and the front page are drawn and stored, so
     * every change has pages to remove and to set aside.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $posts  each post's ID, working revision and address
     *
     * @return array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, prepare: Closure(): void, finish: Closure(): void, before: Closure(int): void}
     */
    private function change(string $name, string $driver, array $posts, string $admin, bool $cdn = false, bool $warm = false): array
    {
        return [
            'name' => $name,
            'group' => 'quickedit',
            'indexOrder' => false,
            'prepare' => function () use ($driver, $cdn, $warm): void {
                $this->on($driver, $cdn, $warm);
            },
            'before' => function (int $i) use ($name, $posts): void {
                $post = $posts[$i] ?? throw new RuntimeException("{$name}: no post left for run {$i}.");
                $this->read($post[2], $i, null);
                $this->read('/', $i, null);
            },
            'run' => function (int $i) use ($posts, $admin): mixed {
                [$contentId, $revisionId] = $posts[$i];

                return app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($contentId, $revisionId, $admin, title: "Benchmark cached retitle {$i}"));
            },
            'finish' => function (): void {
                $this->off();
            },
        ];
    }

    /**
     * The minute's prune with the static-file driver, as the scheduler runs
     * it on an idle site: nothing has expired, since pages live an hour, so
     * this is what it costs each minute, the files' table included.
     *
     * @return array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, prepare: Closure(): void, finish: Closure(): void}
     */
    private function prune(string $name): array
    {
        return [
            'name' => $name,
            'group' => 'scheduler',
            'indexOrder' => false,
            'prepare' => function (): void {
                $this->on('static');
                $this->read('/', 0, null);
            },
            'run' => fn (int $i): int => Artisan::call('teme:cache:prune'),
            'finish' => function (): void {
                $this->off();
            },
        ];
    }

    /**
     * Switches the cache on with this store, in the benchmark's own folder
     * and prefix, pages living an hour (D7); with a made-up Cloudflare zone
     * when $cdn, whose drain is queued and never run, so nothing reaches
     * Cloudflare; with warming when $warm; either on the database queue, as
     * a site with a worker has them.
     */
    private function on(string $driver, bool $cdn = false, bool $warm = false): void
    {
        $this->jobsBefore = (int) DB::table('jobs')->max('id');

        // Outside production every page says noindex, and a page with a header
        // the web server would drop gets no file (I-537): the static-file
        // driver's scenarios run as production, where it writes files.
        if ($driver === 'static') {
            $this->environment = app()->environment();
            app()->instance('env', 'production');
        }

        config([
            'page_cache.enabled' => true,
            'page_cache.lifetime' => 3600,
            'page_cache.driver' => $driver,
            'page_cache.stores.file.path' => $this->folder,
            'page_cache.stores.static.path' => $this->folder.'/static',
            'page_cache.stores.redis.prefix' => self::PREFIX,
            'page_cache.cdn.driver' => $cdn ? 'cloudflare' : null,
            'page_cache.cdn.cloudflare.zone' => 'benchmark-zone',
            'page_cache.cdn.cloudflare.token' => 'benchmark-token',
            'page_cache.warm.enabled' => $warm,
            'page_cache.warm.limit' => 20,
            'queue.default' => $cdn || $warm ? 'database' : $this->queue,
        ]);
    }

    /**
     * Leaves nothing behind (D7): the lock it held, its stored pages and
     * their files, the purges noted, the CDN's queues, the files' rows, the
     * jobs it queued and the CDN drain's unique lock; then the configuration
     * as it was, the cache off again.
     */
    private function off(): void
    {
        $this->held?->release();
        $this->held = null;

        app(PageStore::class)->flush();
        File::deleteDirectory($this->folder.'/'.AddressCounts::FOLDER);
        DB::table('page_cache_purges')->delete();
        DB::table('page_cache_cdn_purges')->delete();
        DB::table('page_cache_cdn_addresses')->delete();
        DB::table('page_cache_files')->delete();
        DB::table('jobs')->where('id', '>', $this->jobsBefore)->delete();
        Cache::lock(UniqueLock::getKey(new PurgeCdn))->forceRelease();

        config(['page_cache' => $this->pageCache, 'queue.default' => $this->queue]);

        if ($this->environment !== null) {
            app()->instance('env', $this->environment);
            $this->environment = null;
        }
    }

    /**
     * A reader's GET of this address at the site's own scheme and host, in a
     * fresh scope (D5): any status but $status, 200 unless a scenario asks
     * for another, or an X-Teme-Cache other than
     * $expected, ends the run, as the runner's public pages end it on any
     * status but 200. Returns what X-Teme-Cache said.
     */
    private function read(string $path, int $i, ?string $expected, int $status = 200): string
    {
        app()->forgetScopedInstances();

        $request = Request::create(rtrim((string) config('app.url'), '/').$path, 'GET', server: ['REMOTE_ADDR' => '198.51.100.'.($i % 250 + 1)]);
        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $said = (string) $response->headers->get('X-Teme-Cache', '');
        $this->lastTags = (string) $response->headers->get('X-Teme-Cache-Tags', '');

        if ($response->getStatusCode() !== $status || ($expected !== null && $said !== $expected)) {
            throw new RuntimeException("The public page {$path} answered {$response->getStatusCode()}, X-Teme-Cache: {$said}".($expected === null ? '.' : ", where {$expected} was expected."));
        }

        return $said;
    }

    /**
     * The page cache's key for this address at the site's own scheme and host.
     */
    private function keyOf(string $path): PageKey
    {
        return PageKey::of(Request::create(rtrim((string) config('app.url'), '/').$path)) ?? throw new RuntimeException("{$path} has no key in the page cache.");
    }

    /**
     * Takes this page's lock from the store, as another request drawing the
     * page would hold it, until off() frees it.
     */
    private function hold(PageKey $key): void
    {
        $lock = app(PageStore::class)->lock($key, PageCache::LOCK_SECONDS);

        if ($lock->get() !== true) {
            throw new RuntimeException("{$key->url}: its lock is held already.");
        }

        $this->held = $lock;
    }

    /**
     * Notes how many tags the page at this address carries, how long they are
     * written out, and how much of them X-Teme-Cache-Tags carried: 2,000
     * characters at most, then how many more, and only outside production.
     */
    private function noteTags(string $scenario, string $path): void
    {
        $entry = app(PageStore::class)->get($this->keyOf($path));

        if ($entry === null) {
            return;
        }

        $this->notes[] = sprintf(
            '%s: %d tags, %s characters written out; X-Teme-Cache-Tags carried %s',
            $scenario,
            count($entry->tags),
            number_format(strlen(implode(' ', $entry->tags))),
            number_format(strlen($this->lastTags)),
        );
    }

    /**
     * Whether a Valkey answers the Redis store's connection, through phpredis,
     * which the store needs (D10). When one does, the commands sent on that
     * connection are counted from then on (Tally::command()); when none does,
     * the notes say where, and why.
     */
    private function valkeyAnswers(): bool
    {
        $connection = (string) config('page_cache.stores.redis.connection');

        try {
            // Events reach a connection made after they are switched on.
            Redis::enableEvents();
            Redis::purge($connection);

            if (! Redis::connection($connection) instanceof PhpRedisConnection) {
                throw new RuntimeException('the Redis store needs phpredis (REDIS_CLIENT=phpredis)');
            }

            Redis::connection($connection)->ping();
        } catch (Throwable $unanswered) {
            $this->notes[] = sprintf(
                'The Redis store: not measured. No Valkey answered at %s:%s (%s).',
                (string) config("database.redis.{$connection}.host"),
                (string) config("database.redis.{$connection}.port"),
                $unanswered->getMessage(),
            );

            return false;
        }

        Redis::connection($connection)->listen(function (CommandExecuted $event) use ($connection): void {
            if ($event->connectionName === $connection) {
                $this->tally->command();
            }
        });

        return true;
    }

    /**
     * The addresses of $count published posts, picked before any timing:
     * ORDER BY random() only locates the rows.
     *
     * @return list<string>
     */
    private function articles(int $count): array
    {
        return DB::table('paths')
            ->join('contents', 'paths.target_id', '=', DB::raw('contents.id::text'))
            ->where('paths.target_type', 'content')
            ->where('paths.is_current', true)
            ->where('contents.type', 'post')
            ->orderByRaw('random()')
            ->limit($count)
            ->pluck('paths.path')
            ->map(fn (mixed $path): string => Addresses::toUrl((string) $path, 'post'))
            ->values()
            ->all();
    }

    /**
     * Published, live posts with nothing waiting (the working revision is the
     * published one), from the top of the data set's lower half downward,
     * which no scenario before the address recompute writes to, leaving out
     * the posts other scenarios edit: each with its revision and address.
     *
     * @param  list<string>  $exclude
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function editable(int $count, array $exclude): array
    {
        return DB::table('contents')
            ->join('paths', fn ($join) => $join->on('paths.target_id', '=', DB::raw('contents.id::text'))->where('paths.target_type', 'content')->where('paths.is_current', true))
            ->where('contents.type', 'post')
            ->where('contents.status', 'published')
            ->whereNull('contents.deleted_at')
            ->whereColumn('contents.working_revision_id', 'contents.published_revision_id')
            ->whereRaw('substr(contents.id, 2)::bigint % 100 <> 51')
            ->whereRaw('substr(contents.id, 2)::bigint > 2')
            ->whereRaw('substr(contents.id, 2)::bigint < (SELECT count(*) / 2 FROM contents)')
            ->whereNotIn('contents.id', $exclude)
            ->orderByDesc('contents.id')
            ->limit($count)
            ->get(['contents.id', 'contents.working_revision_id', 'paths.path'])
            ->map(fn (object $row): array => [(string) $row->id, (string) $row->working_revision_id, Addresses::toUrl((string) $row->path, 'post')])
            ->values()
            ->all();
    }

    /**
     * A target's current address, as stored.
     */
    private function addressOf(string $type, string $id): string
    {
        return (string) DB::table('paths')->where('target_type', $type)->where('target_id', $id)->where('is_current', true)->value('path');
    }
}
