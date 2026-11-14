<?php

declare(strict_types=1);

namespace App\Domain\Benchmark;

use App\Domain\Addresses\Addresses;
use App\Domain\Redirects\AddressCounts;
use App\Domain\Redirects\NotFoundLogSection;
use App\Domain\Redirects\Operations\Input\RecordAddressCountsInput;
use App\Domain\Redirects\Operations\RecordAddressCounts;
use App\Domain\Redirects\Queries\NotFoundEntries;
use App\Domain\Redirects\Queries\RedirectList;
use App\Domain\Redirects\RedirectLookup;
use App\Domain\Redirects\RedirectTarget;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SocialCards;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Sitemaps\ItemsSitemap;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Domain\Sitemaps\SitemapProvider;
use App\Domain\Sitemaps\Sitemaps;
use App\Domain\Sitemaps\SitemapXml;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The scenarios docs/studies/seo.md §3.15 sets: a redirect found among
 * 100,000, as a lookup and as a reader's request; addresses nothing
 * answers, each request answered with no write, and the minute's run that
 * writes a full log's worth of them into a log at its cap; that log's
 * screen and Tools › Redirects at their size; the sitemap index and its
 * deepest page of posts; and an article whose head takes every fallback.
 * A reader's request notes what it met in a spool folder of the
 * benchmark's own, never the site's, and each scenario puts back what it
 * changes.
 *
 * @phpstan-type Scenario array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, queriesLike?: string, items?: int, within?: list<array{items: int, seconds: int, what: string}>, planWrites?: bool, writesNothing?: bool, reads?: list<string>, prepare?: Closure(): void, finish?: Closure(): void, before?: Closure(int): void}
 */
final class SeoScenarios
{
    /** New addresses one timed run of the flood asks for, a request each. */
    public const int FLOOD_RUN = 100;

    /** Lookups one timed run makes. */
    private const int LOOKUPS = 1000;

    /** Rows a page of the 404 log and of Tools › Redirects shows. */
    private const int PAGE = 50;

    /** The posts sitemap's page asked for, or its last where it has fewer (docs/studies/seo.md §3.15). */
    private const int SITEMAP_PAGE = 500;

    /** The fewest and the most addresses the 404 log may keep (I-874). */
    private const int SMALLEST_LOG = 1000;

    private const int LARGEST_LOG = 100_000;

    /** What the minute's run may take, so the next starts on time: it runs every minute, never two at once. */
    private const int MINUTE_SECONDS = 30;

    /** The folder, inside the page cache's, where the scenarios' requests note what they met. */
    private const string SPOOL = 'benchmark-seo';

    /** The settings the article's scenario sets, and puts back. */
    private const array FALLBACK_SETTINGS = [SocialCards::DEFAULT_IMAGE, 'publisher_type', 'publisher_name', 'publisher_logo'];

    /** @var list<string> What the last all() noted beside the table. */
    private array $notes = [];

    /** The page cache's folder before a scenario moved the spool, or null while none has. */
    private ?string $siteFolder = null;

    /** The elevation in place before the minute's run took the operator's. */
    private ?Elevation $elevation = null;

    public function __construct(
        private readonly RedirectLookup $lookup,
        private readonly NotFoundEntries $log,
        private readonly RedirectList $redirects,
        private readonly ListedItems $listed,
    ) {}

    /**
     * Every scenario the data set can run, with a note for each it can't.
     * $reader draws a public page that must answer 200 as a reader's request.
     *
     * @param  Closure(string): (Closure(int): int)  $reader
     *
     * @return list<Scenario>
     */
    public function all(int $runs, string $admin, Closure $reader): array
    {
        $this->notes = [];
        $scenarios = [];
        $logged = DB::table('not_found_log')->count();

        if (DB::table('redirects')->exists()) {
            $scenarios = [...$this->lookups(), ...$this->answers($runs), ...$this->redirectsScreen()];
        } else {
            $this->notes[] = 'Redirects: not measured, since the data set has none (teme:bench:seed --redirects).';
        }

        if ($logged >= self::SMALLEST_LOG) {
            $scenarios = [...$scenarios, ...$this->notFound($logged, $admin)];
        } else {
            $this->notes[] = 'The 404 log: not measured, since the data set holds fewer addresses than the smallest log keeps, 1,000 (teme:bench:seed --not-found).';
        }

        return [...$scenarios, ...$this->sitemaps($reader), ...$this->head($admin, $reader)];
    }

    /**
     * What the last all() noted beside the table.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * $count addresses nothing answers, each new: under a first segment no
     * item, archive or redirect uses, then sixteen random hexadecimal
     * characters, as a flood of made-up addresses arrives.
     *
     * @return list<string>
     */
    public static function newAddresses(int $count, string $under = 'flood'): array
    {
        return array_map(fn (int $n): string => '/'.$under.'/'.bin2hex(random_bytes(8)).'/', range(1, max(1, $count)));
    }

    /**
     * One lookup as a reader's request makes it where routing missed: an
     * exact redirect to an address, found by its source's unique index; and
     * an address no redirect answers, tried against every pattern once the
     * index says no. Timed a lookup each, as address.lookup is.
     *
     * @return list<Scenario>
     */
    private function lookups(): array
    {
        // Picked before timing: ORDER BY random() only locates the rows.
        $found = DB::table('redirects')->where('kind', 'exact')->where('enabled', true)->where('target_kind', RedirectTarget::ADDRESS)
            ->orderByRaw('random()')->limit(self::LOOKUPS)->pluck('source')->map(fn (mixed $source): string => '/'.$source)->all();
        $missing = self::newAddresses(self::LOOKUPS, 'gone');

        return [
            [
                'name' => 'redirect.lookup: exact',
                'group' => 'address',
                'indexOrder' => false,
                'items' => max(1, count($found)),
                'reads' => ['redirects_source_unique'],
                'run' => function (int $i) use ($found): int {
                    foreach ($found as $path) {
                        $this->lookup->answer($path, '') ?? throw new RuntimeException("No redirect answered {$path}.");
                    }

                    return count($found);
                },
            ],
            [
                'name' => 'redirect.lookup: none, every pattern tried',
                'group' => 'address',
                'indexOrder' => false,
                'items' => count($missing),
                'reads' => ['redirects_source_unique'],
                'run' => function (int $i) use ($missing): int {
                    foreach ($missing as $path) {
                        if ($this->lookup->answer($path, '') !== null) {
                            throw new RuntimeException("A redirect answered {$path}, made up to match none.");
                        }
                    }

                    return count($missing);
                },
            ],
        ];
    }

    /**
     * A reader's request for an old address, answered by a redirect among
     * all of them: to an address on the site, and to an item at its address
     * now, which reads the item and its path too. Never stored (I-262), so
     * every one is drawn as an uncached page is. Each run asks for another.
     *
     * @return list<Scenario>
     */
    private function answers(int $runs): array
    {
        $scenarios = [];

        foreach ([RedirectTarget::ADDRESS => 'to an address', RedirectTarget::CONTENT => 'to an item'] as $kind => $label) {
            // Picked before timing: ORDER BY random() only locates the rows.
            $sources = DB::table('redirects')->where('kind', 'exact')->where('enabled', true)->where('target_kind', $kind)
                ->orderByRaw('random()')->limit($runs + 2)->pluck('source')->map(fn (mixed $source): string => '/'.$source)->all();

            if ($sources === []) {
                continue;
            }

            $scenarios[] = [
                'name' => "public: redirect, {$label}",
                'group' => 'public-item',
                'indexOrder' => false,
                'reads' => ['redirects_source_unique'],
                'prepare' => function (): void {
                    $this->ownSpool();
                },
                'run' => fn (int $i): int => $this->answered($sources[$i % count($sources)], $i, redirect: true),
                'finish' => function (): void {
                    $this->siteSpool();
                },
            ];
        }

        return $scenarios;
    }

    /**
     * Tools › Redirects at the data set's size: its first page and one
     * halfway down, held to the first's statements; the tabs' counts; and a
     * search for a word every source holds and for one source alone.
     *
     * @return list<Scenario>
     */
    private function redirectsScreen(): array
    {
        // Picked before timing: OFFSET only locates the row.
        $halfway = (string) DB::table('redirects')->orderByDesc('id')->offset(intdiv(DB::table('redirects')->count(), 2))->limit(1)->value('id');
        $one = (string) DB::table('redirects')->where('kind', 'exact')->orderByRaw('random()')->limit(1)->value('source');

        return [
            ['name' => 'redirects: page one', 'group' => 'list', 'indexOrder' => true, 'reads' => ['redirects_pkey'], 'run' => fn (int $i): array => $this->redirects->page('', self::PAGE)],
            ['name' => 'redirects: halfway down', 'group' => 'list', 'indexOrder' => true, 'queriesLike' => 'redirects: page one', 'reads' => ['redirects_pkey'], 'run' => fn (int $i): array => $this->redirects->page('', self::PAGE, older: $halfway)],
            ['name' => 'redirects: the tabs\' counts', 'group' => 'list', 'indexOrder' => false, 'run' => fn (int $i): array => $this->redirects->counts()],
            ['name' => 'redirects: search, a word every source holds', 'group' => 'search', 'indexOrder' => false, 'run' => fn (int $i): array => $this->redirects->page('story', self::PAGE)],
            ['name' => 'redirects: search, one source', 'group' => 'search', 'indexOrder' => false, 'run' => fn (int $i): array => $this->redirects->page('/'.$one, self::PAGE)],
        ];
    }

    /**
     * The 404 log at its cap (I-298): its screen's first page and one
     * halfway down; a flood of new addresses, each request answered 404 with
     * no write; and the minute's run that writes as many new addresses as
     * the log keeps into it, after which it must hold its cap. The log's
     * size is set to what the data set holds, within what Search and social
     * allows, and put back with the log's rows after.
     *
     * @return list<Scenario>
     */
    private function notFound(int $logged, string $admin): array
    {
        $cap = min(self::LARGEST_LOG, $logged);
        // Picked before timing: OFFSET only locates the row.
        $halfway = DB::table('not_found_log')->orderByDesc('count')->orderBy('path')->offset(intdiv($logged, 2))->limit(1)->first(['count', 'path']);
        $after = $halfway === null ? null : ['count' => (int) $halfway->count, 'path' => (string) $halfway->path];
        $flood = [];

        return [
            ['name' => '404 log: page one', 'group' => 'list', 'indexOrder' => true, 'reads' => ['not_found_log_most_asked_index'], 'run' => fn (int $i): array => $this->log->mostAsked(self::PAGE)],
            ['name' => '404 log: halfway down', 'group' => 'list', 'indexOrder' => true, 'queriesLike' => '404 log: page one', 'reads' => ['not_found_log_most_asked_index'], 'run' => fn (int $i): array => $this->log->mostAsked(self::PAGE, $after)],
            [
                'name' => 'public: not found, a new address each request',
                'group' => 'public-item',
                'indexOrder' => false,
                'items' => self::FLOOD_RUN,
                'writesNothing' => true,
                'prepare' => function (): void {
                    $this->ownSpool();
                },
                'before' => function (int $i) use (&$flood): void {
                    $flood = self::newAddresses(self::FLOOD_RUN);
                },
                'run' => function (int $i) use (&$flood): int {
                    foreach ($flood as $path) {
                        $this->answered($path, $i);
                    }

                    return count($flood);
                },
                'finish' => function (): void {
                    $this->siteSpool();
                },
            ],
            [
                'name' => '404 log: the minute\'s run, a full log of new addresses',
                'group' => 'scheduler',
                'indexOrder' => false,
                'items' => max(1, intdiv($cap, 1000)),
                'planWrites' => true,
                'within' => [['items' => intdiv(self::LARGEST_LOG, 1000), 'seconds' => self::MINUTE_SECONDS, 'what' => 'a minute\'s flood as large as the largest log, written before the next minute\'s run']],
                'prepare' => function () use ($admin, $cap): void {
                    $this->ownSpool();
                    $this->settings($admin, [NotFoundLogSection::SIZE => $cap], []);
                    // The operator's, as the scheduler's run is (docs/architecture/operations.md, The operator).
                    $this->elevation = app(Elevation::class);
                    app()->instance(Elevation::class, new OperatorElevation);
                },
                'before' => function (int $i) use ($cap): void {
                    $counts = app(AddressCounts::class);
                    $folder = $counts->folder();
                    File::deleteDirectory($folder);

                    foreach (self::newAddresses($cap) as $path) {
                        $counts->notFound($path, 'https://search.example.com/?q=old');
                    }

                    // Closed, as the run a minute before would have closed it.
                    rename($folder.'/'.AddressCounts::OPEN, $folder.'/'.AddressCounts::CLOSED);
                    memory_reset_peak_usage();
                },
                'run' => function (int $i) use ($cap): array {
                    $read = app(RecordAddressCounts::class)->execute(new RecordAddressCountsInput(keep: NotFoundLogSection::size(), ignore: NotFoundLogSection::patterns()));

                    if ($read['notFound'] !== $cap) {
                        throw new RuntimeException("The minute's run read {$read['notFound']} addresses of {$cap}.");
                    }

                    return $read;
                },
                'finish' => function () use ($admin, $cap, $logged): void {
                    $held = DB::table('not_found_log')->count();
                    $this->notes[] = sprintf("The minute's run: %s new addresses into a log of %s; it held %s after, and the process used at most %.0f MB.", number_format($cap), number_format($cap), number_format($held), memory_get_peak_usage() / 1_048_576);

                    if ($this->elevation !== null) {
                        app()->instance(Elevation::class, $this->elevation);
                        $this->elevation = null;
                    }

                    // The seeded rows back, as the scenario found them.
                    DB::table('not_found_log')->delete();
                    app(BenchmarkSeeder::class)->notFoundLog($logged);
                    $this->settings($admin, [], [NotFoundLogSection::SIZE]);
                    $this->siteSpool();

                    if ($held !== $cap) {
                        throw new RuntimeException("The 404 log held {$held} addresses after the minute's run, not its cap of {$cap}.");
                    }
                },
            ],
        ];
    }

    /**
     * The sitemaps at the data set's size (I-296): the index with every
     * child's pages found afresh, as a miss of the page cache finds them;
     * and the posts sitemap's first page, its second, and page 500 or its
     * last, held to the second's statements, since the first reads no kept
     * key. Every one reads contents_sitemap.
     *
     * @param  Closure(string): (Closure(int): int)  $reader
     *
     * @return list<Scenario>
     */
    private function sitemaps(Closure $reader): array
    {
        if (! $this->listed->any('post')) {
            $this->notes[] = 'Sitemaps: not measured, since the data set has no live post.';

            return [];
        }

        // Read before timing.
        $starts = $this->listed->starts('post');
        $deep = min(self::SITEMAP_PAGE, count($starts) + 1);
        $live = DB::table('contents')->where('type', 'post')->where('status', 'published')->whereNull('deleted_at')->where('published_at', '<=', now())->count();
        $file = fn (?string $from): string => '/sitemap-'.ItemsSitemap::PREFIX.'post'.($from === null ? '' : '.'.$from).'.xml';
        $kept = function () use ($reader): void {
            $reader('/sitemap.xml')(0);
        };
        $afresh = function (int $i): void {
            foreach (app()->tagged(SitemapProvider::TAG) as $provider) {
                if ($provider instanceof SitemapProvider) {
                    foreach ($provider->names() as $name) {
                        Cache::forget(Sitemaps::KEPT.$name);
                    }
                }
            }
        };

        $this->notes[] = sprintf('Sitemaps: %s live posts make %s pages of %s; page %d is the deepest asked for.', number_format($live), number_format(count($starts) + 1), number_format(ListedItems::PAGE), $deep);

        // A later page's address carries its first key in lower case, as a ULID is stored; the seeded
        // IDs start with a capital, so such a page is drawn as its address would draw it, without the kernel.
        $later = fn (string $from): Closure => preg_match(Sitemaps::KEY, $from) === 1 ? $reader($file($from)) : function (int $i) use ($from): int {
            app()->forgetScopedInstances();

            return strlen(SitemapXml::urlset(app(ItemsSitemap::class)->page(ItemsSitemap::PREFIX.'post', $from)));
        };

        if ($starts !== [] && preg_match(Sitemaps::KEY, $starts[0]) !== 1) {
            $this->notes[] = 'Sitemaps: the seeded IDs start with a capital, which no page\'s address takes, so page 2 and the deepest are drawn as their addresses would draw them, by the posts sitemap and its XML, without the HTTP kernel.';
        }

        $scenarios = [
            ['name' => 'public: sitemap index, its pages found afresh', 'group' => 'public-list', 'indexOrder' => false, 'reads' => ['contents_sitemap'], 'before' => $afresh, 'run' => $reader('/sitemap.xml')],
            ['name' => 'public: sitemap, posts page one', 'group' => 'public-list', 'indexOrder' => false, 'reads' => ['contents_sitemap'], 'prepare' => $kept, 'run' => $reader($file(null))],
        ];

        if ($deep >= 2) {
            $scenarios[] = ['name' => 'public: sitemap, posts page 2', 'group' => 'public-list', 'indexOrder' => false, 'reads' => ['contents_sitemap'], 'prepare' => $kept, 'run' => $later($starts[0])];
        }

        if ($deep >= 3) {
            $scenarios[] = ['name' => "public: sitemap, posts page {$deep}", 'group' => 'public-deep', 'indexOrder' => false, 'queriesLike' => 'public: sitemap, posts page 2', 'reads' => ['contents_sitemap'], 'prepare' => $kept, 'run' => $later($starts[$deep - 2])];
        }

        return $scenarios;
    }

    /**
     * An article whose head takes every fallback (docs/studies/seo.md
     * §3.15): with nothing of its own but an excerpt and a canonical naming a
     * page with no address, its title comes from its type's template, its
     * description from the excerpt, its social values from those, its image
     * from the site's default, and its canonical is its own; and the site is
     * published by an organization with a logo, the publisher that reads the
     * most. Set through UpdateSettings for the scenario, and put back.
     *
     * @param  Closure(string): (Closure(int): int)  $reader
     *
     * @return list<Scenario>
     */
    private function head(string $admin, Closure $reader): array
    {
        $path = DB::table('paths')->where('target_type', 'content')->where('target_id', BenchmarkSeeder::itemId(BenchmarkSeeder::FALLBACK_ITEM))->where('is_current', true)->value('path');
        $images = DB::table('media')->where('kind', 'image')->whereNull('deleted_at')->where('width', '>=', 600)->orderBy('id')->limit(2)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

        if ($path === null || count($images) < 2) {
            $this->notes[] = 'The head with every fallback: not measured, since the data set has no item '.BenchmarkSeeder::FALLBACK_ITEM.' or fewer than two images.';

            return [];
        }

        return [[
            'name' => 'public: article, every fallback in play',
            'group' => 'public-item',
            'indexOrder' => false,
            'prepare' => function () use ($admin, $images): void {
                $this->settings($admin, [SocialCards::DEFAULT_IMAGE => $images[0], 'publisher_type' => 'Organization', 'publisher_name' => 'The Bench Ledger', 'publisher_logo' => $images[1]], []);
            },
            'run' => $reader(Addresses::toUrl((string) $path, 'post')),
            'finish' => function () use ($admin): void {
                $this->settings($admin, [], self::FALLBACK_SETTINGS);
            },
        ]];
    }

    /**
     * A reader's GET of this address in a fresh scope, as every production
     * request starts, from a search engine's results; it must answer 404,
     * or a redirect with somewhere to go, or the run ends, since another
     * answer timed would be the wrong number.
     */
    private function answered(string $path, int $i, bool $redirect = false): int
    {
        app()->forgetScopedInstances();

        $request = Request::create($path, 'GET', server: ['REMOTE_ADDR' => '198.51.100.'.($i % 250 + 1), 'HTTP_REFERER' => 'https://search.example.com/?q=old']);
        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $status = $response->getStatusCode();
        $expected = $redirect ? $response->isRedirection() && (string) $response->headers->get('Location', '') !== '' : $status === 404;

        if (! $expected) {
            throw new RuntimeException("{$path} answered {$status}, not ".($redirect ? 'a redirect.' : '404.'));
        }

        return $status;
    }

    /**
     * Sets and resets Search and social's values through UpdateSettings, as
     * its screen does, as the benchmark's administrator, with what it queues
     * discarded, on the default connection and the long one: the card a
     * chosen default image queues (I-855) is a worker's, and the benchmark's
     * media have no files to make it from. It works on a fresh queue manager,
     * since a connection the run already resolved keeps its driver.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $reset
     */
    private function settings(string $admin, array $values, array $reset): void
    {
        $version = (int) DB::table('settings')->where('group_key', SearchAndSocial::KEY)->value('version');
        $queue = config('queue.default');
        $long = config('queue.connections.database-long');
        $manager = app('queue');

        config([
            'queue.connections.benchmark-discard' => ['driver' => 'null'],
            'queue.default' => 'benchmark-discard',
            'queue.connections.database-long' => ['driver' => 'null'],
        ]);
        // A fresh manager: the long connection may already be resolved, and a resolved one keeps its driver.
        app()->forgetInstance('queue');

        try {
            app(UpdateSettings::class)->execute(new UpdateSettingsInput(SearchAndSocial::KEY, $values, $reset, [], $version, $admin));
        } finally {
            config(['queue.default' => $queue, 'queue.connections.database-long' => $long]);
            app()->instance('queue', $manager);
        }
    }

    /**
     * Points the spool readers' requests write at a folder of the
     * benchmark's own inside the page cache's, emptied first, so no line a
     * scenario's request writes reaches the site's own 404 log or its
     * redirects' hits (I-298). The page cache is off in these scenarios, so
     * moving its folder moves nothing else.
     */
    private function ownSpool(): void
    {
        $this->siteFolder ??= (string) config('page_cache.stores.file.path');
        $folder = rtrim($this->siteFolder, '/\\').'/'.self::SPOOL;

        File::deleteDirectory($folder);
        config(['page_cache.stores.file.path' => $folder]);
    }

    /**
     * The site's folder back, and the benchmark's removed with every line in it.
     */
    private function siteSpool(): void
    {
        if ($this->siteFolder === null) {
            return;
        }

        File::deleteDirectory(rtrim($this->siteFolder, '/\\').'/'.self::SPOOL);
        config(['page_cache.stores.file.path' => $this->siteFolder]);
        $this->siteFolder = null;
    }
}
