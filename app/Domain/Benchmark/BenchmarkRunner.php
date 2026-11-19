<?php

/**
 * Replays every list, tab, sort, filter, search, term screen, quick-edit
 * save and address lookup against the data set, the page cache's hits,
 * misses and changes (CacheScenarios), and the comments' (CommentScenarios), the SEO scenarios (SeoScenarios), the AI record's (AiScenarios), timing each one and checking its
 * query plans against the scale rules (I-148). Every scenario
 * calls the production query classes and Operations — none reimplements a
 * query — so what it measures is what the admin does.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Benchmark;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Operations\ChangeAddresses;
use App\Domain\Addresses\Operations\Input\ChangeAddressesInput;
use App\Domain\Addresses\Operations\Input\RebuildPathsInput;
use App\Domain\Addresses\Operations\RebuildPaths;
use App\Domain\Addresses\Paths;
use App\Domain\Cards\DocumentDiff;
use App\Domain\Contents\BulkEditItem;
use App\Domain\Contents\Operations\CheckpointDraft;
use App\Domain\Contents\Operations\Input\CheckpointDraftInput;
use App\Domain\Contents\Operations\Input\SaveAutosaveInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\SaveAutosave;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Contents\Queries\ContentList;
use App\Domain\Contents\Queries\ContentListRequest;
use App\Domain\Contents\Queries\ContentPointers;
use App\Domain\Contents\Queries\EditorSession;
use App\Domain\Contents\Queries\RevisionHistory;
use App\Domain\Dashboard\Queries\Dashboard;
use App\Domain\Media\Queries\MediaLibrary;
use App\Domain\Media\Queries\MediaLibraryRequest;
use App\Domain\Media\Queries\MediaMonths;
use App\Domain\Media\Queries\MediaPicker;
use App\Domain\Media\Queries\MediaUsageList;
use App\Domain\Search\Queries\AdminSearch;
use App\Domain\Site\ListingViews;
use App\Domain\Site\PathResolver;
use App\Domain\Site\ViewModels\Pagination;
use App\Domain\Support\Queries\Cursor;
use App\Domain\Taxonomy\Queries\TermList;
use App\Domain\Taxonomy\Queries\TermPicker;
use App\Domain\Taxonomy\Queries\TermScreen;
use App\Domain\Users\Actors;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BenchmarkRunner
{
    /**
     * The I-148 classes, p95 in milliseconds. `editor` is the list
     * budget under its own name, so the report reads the editor's opening
     * apart from the lists; `scheduler` is one pass of up to 50 rows and
     * `address` one path lookup, an index read (1.6a); neither is from I-148.
     */
    private const array BUDGETS = [
        'list' => 150, 'filtered' => 200, 'search' => 300, 'terms' => 100, 'quickedit' => 150, 'editor' => 150, 'scheduler' => 500, 'address' => 2,
        // The public site (I-400): server time only, from the request reaching
        // the kernel to the HTML leaving it; a release check, never a site's limit.
        'public-item' => 100, 'public-list' => 150, 'public-deep' => 200, 'public-search' => 300,
        // A page answered from the page cache (question 2, 1.7e):
        // a tenth of the tightest page's, and it may read nothing (D8).
        'public-hit' => 10,
    ];

    /** The tables a sequential scan of breaks the scale rule (I-148): those that grow with the site. The 404 log is capped by its own design (I-298), so its screen's index is checked by a scenario's reads instead. */
    private const array TABLES = ['contents', 'content_revisions', 'content_terms', 'terms', 'users', 'media', 'media_usages', 'content_reviews', 'scheduled_publications', 'content_autosaves', 'paths', 'comments', 'comment_stats', 'redirects', 'ai_activity'];

    /**
     * Words five items in a thousand hold together (5,444 published at a
     * million, about five times the search cap): the middle ground where
     * walking the newest matches would read far past the cap, so the planner
     * must take the full-text index (I-458).
     */
    private const string MIDDLING_WORDS = 'tidewater garden river market harvest';

    /** The pattern address.recompute moves posts to. */
    private const string YEAR_PATTERN = '/{year}/{slug}/';

    /** Items one Bulk Edit request carries (BULK_EDIT_BATCH in the browser). */
    private const int BULK_BATCH = 25;

    /** What each inspected run wrote, and sent to Valkey (D6). */
    private Tally $tally;

    /** Whether the benchmark's connection tells the tally of each statement yet. */
    private bool $listening = false;

    /** The page cache's scenarios, made afresh for each run. */
    private CacheScenarios $cache;

    /** The comment scenarios, made afresh for each run. */
    private CommentScenarios $comments;

    /** The SEO scenarios, made afresh for each run. */
    private SeoScenarios $seo;

    /** The AI record's scenarios, made afresh for each run. */
    private AiScenarios $ai;

    /**
     * What the last run noted beside its table.
     *
     * @var list<string>
     */
    private array $notes = [];

    public function __construct(
        private readonly ContentList $list,
        private readonly TermList $terms,
        private readonly TermPicker $picker,
        private readonly ContentPointers $pointers,
        private readonly PlanInspector $inspector,
        private readonly MediaLibrary $library,
        private readonly MediaPicker $mediaPicker,
        private readonly MediaUsageList $usages,
    ) {
        $this->tally = new Tally;
    }

    /**
     * Runs every scenario once to warm up, then $runs times, and returns each
     * one's 95th-percentile time, any query-plan rule it broke, and its query
     * count. Measured as 1.6h measured them, without the page cache, whose
     * hits would stand in for the public pages and whose purges would add
     * to each Operation; the cache's own scenarios switch it on for
     * themselves (I-460).
     *
     * @return list<ScenarioResult>
     */
    public function run(int $runs): array
    {
        $cached = (bool) config('page_cache.enabled');
        config(['page_cache.enabled' => false]);

        $this->cache = new CacheScenarios($this->tally);
        $this->comments = app(CommentScenarios::class);
        $this->seo = app(SeoScenarios::class);
        $this->ai = app(AiScenarios::class);
        $this->notes = [];

        // Every statement is seen as it is sent, a failed one too (D6).
        if (! $this->listening) {
            DB::connection()->beforeExecuting(function (string $query): void {
                $this->tally->statement($query);
            });
            $this->listening = true;
        }

        try {
            return $this->measured($runs);
        } finally {
            $this->notes = [...$this->cache->notes(), ...$this->comments->notes(), ...$this->seo->notes(), ...$this->ai->notes()];
            config(['page_cache.enabled' => $cached]);
        }
    }

    /**
     * What the last run noted beside its table: the tags each cached page
     * carries, and what it couldn't measure, such as the Redis store where no
     * Valkey answered (D10).
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * Runs every scenario once to warm up, then $runs times, and returns each
     * one's 95th-percentile time, any query-plan rule it broke, and its query
     * count — compared, after every scenario has run, against the scenario
     * its own 'queriesLike' names (check 4): a deep page must cost what page
     * one costs.
     *
     * @return list<ScenarioResult>
     */
    private function measured(int $runs): array
    {
        $sizes = $this->tableSizes();
        $raw = [];

        foreach ($this->scenarios($runs) as $scenario) {
            // Setting a scenario up, and putting things back after it, sit
            // outside its timing; putting back happens even if it fails.
            if (isset($scenario['prepare'])) {
                $scenario['prepare']();
            }

            try {
                $raw[$scenario['name']] = $this->measure($scenario, $runs, $sizes);
            } finally {
                if (isset($scenario['finish'])) {
                    $scenario['finish']();
                }
            }
        }

        foreach ($raw as $name => $data) {
            $like = $data['queriesLike'];

            if ($like !== null && $raw[$like]['queries'] !== $data['queries']) {
                $raw[$name]['problems'][] = sprintf("%d queries, but '%s' ran %d", $data['queries'], $like, $raw[$like]['queries']);
            }
        }

        return array_map(
            fn (string $name, array $data): ScenarioResult => new ScenarioResult(
                $name,
                (string) $data['group'],
                self::BUDGETS[$data['group']],
                (float) $data['p95Ms'],
                $data['problems'],
                (int) $data['queries'],
                (int) $data['writes'],
                (int) $data['commands'],
                $data['indexes'],
            ),
            array_keys($raw),
            $raw,
        );
    }

    /**
     * One scenario warmed up once, timed $runs times, then run once more with
     * its SELECTs planned and counted, and its writes and Valkey commands
     * counted (D6). A scenario's `before` step runs before each of them,
     * outside the timing and the counting; a hit that reads the database is
     * a problem, whatever its time (D8). Every promise a scenario makes is
     * checked on that last run by ScenarioChecks.
     *
     * @param  array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, queriesLike?: string, items?: int, before?: Closure(int): void, readsNothing?: bool, writesNothing?: bool, reads?: list<string>, within?: list<array{items: int, seconds: int, what: string}>, planWrites?: bool}  $scenario
     * @param  array<string, int>  $sizes
     *
     * @return array{group: string, p95Ms: float, problems: list<string>, queries: int, writes: int, commands: int, queriesLike: string|null, indexes: list<string>}
     */
    private function measure(array $scenario, int $runs, array $sizes): array
    {
        $run = $scenario['run'];
        $before = $scenario['before'] ?? null;

        if ($before !== null) {
            $before(0);
        }

        $run(0);

        $times = [];

        // A per-item scenario's time is divided by its items, so its p95
        // is one item's and its budget one save's.
        for ($i = 1; $i <= $runs; $i++) {
            if ($before !== null) {
                $before($i);
            }

            $start = hrtime(true);
            $run($i);
            $times[] = (hrtime(true) - $start) / 1_000_000 / ($scenario['items'] ?? 1);
        }

        sort($times);
        $p95 = $times[max(0, (int) ceil(0.95 * $runs) - 1)];

        if ($before !== null) {
            $before($runs + 1);
        }

        $inspected = $this->inspect($run, $runs + 1, $sizes, $scenario['indexOrder'], $scenario['planWrites'] ?? false);
        $problems = [...$inspected['problems'], ...ScenarioChecks::problems($scenario, $p95, $inspected['queries'], $inspected['writes'], $inspected['indexes'])];

        return [
            'group' => $scenario['group'],
            'p95Ms' => max($p95, 0.001),
            'problems' => $problems,
            'queries' => $inspected['queries'],
            'writes' => $inspected['writes'],
            'commands' => $inspected['commands'],
            'queriesLike' => $scenario['queriesLike'] ?? null,
            'indexes' => $inspected['indexes'],
        ];
    }

    /**
     * @return list<array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, queriesLike?: string, items?: int, prepare?: Closure(): void, finish?: Closure(): void, before?: Closure(int): void, readsNothing?: bool, writesNothing?: bool, reads?: list<string>, within?: list<array{items: int, seconds: int, what: string}>, planWrites?: bool}>
     */
    private function scenarios(int $runs): array
    {
        $author = (int) DB::table('users')->where('email', 'author2@bench.test')->value('id');
        $filterAuthor = (int) DB::table('users')->where('email', 'author100@bench.test')->value('id');
        $admin = (string) DB::table('users')->where('email', 'admin@bench.test')->value('id');
        $category = 'T'.str_pad('1', 25, '0', STR_PAD_LEFT);
        $tag = 'T'.str_pad('2001', 25, '0', STR_PAD_LEFT);

        $page = fn (array $arguments): Closure => fn (int $i): array => $this->list->page(new ContentListRequest(...array_merge(['type' => 'post'], $arguments)));
        $scenarios = [];

        // Each tab, sorted by updated: page one, and a page halfway down.
        foreach (['all', 'published', 'drafts', 'mine'] as $tab) {
            $base = ['tab' => $tab, 'viewerId' => $author];
            $scenarios[] = ['name' => "{$tab} tab, by updated, page one", 'group' => 'list', 'indexOrder' => true, 'run' => $page($base)];
            $scenarios[] = ['name' => "{$tab} tab, by updated, deep page", 'group' => 'list', 'indexOrder' => true, 'queriesLike' => "{$tab} tab, by updated, page one", 'run' => $page([...$base, 'after' => $this->deepCursor($tab, $author, 'updated_at', 'desc')])];
        }

        $scenarios[] = ['name' => 'all tab, by title, page one', 'group' => 'filtered', 'indexOrder' => true, 'run' => $page(['sort' => 'title', 'direction' => 'asc'])];
        $scenarios[] = ['name' => 'all tab, by title, deep page', 'group' => 'filtered', 'indexOrder' => true, 'queriesLike' => 'all tab, by title, page one', 'run' => $page(['sort' => 'title', 'direction' => 'asc', 'after' => $this->deepCursor('all', $author, 'title', 'asc')])];
        $scenarios[] = ['name' => 'published tab, by published date', 'group' => 'filtered', 'indexOrder' => true, 'run' => $page(['tab' => 'published', 'sort' => 'published'])];
        $scenarios[] = ['name' => 'trash tab', 'group' => 'filtered', 'indexOrder' => true, 'run' => $page(['tab' => 'trash'])];
        $scenarios[] = ['name' => 'author filter', 'group' => 'filtered', 'indexOrder' => false, 'run' => $page(['authorId' => $filterAuthor])];
        $scenarios[] = ['name' => 'category filter, with children', 'group' => 'filtered', 'indexOrder' => false, 'run' => $page(['terms' => ['category' => $category]])];
        $scenarios[] = ['name' => 'tag filter', 'group' => 'filtered', 'indexOrder' => false, 'run' => $page(['terms' => ['tag' => $tag]])];
        $scenarios[] = ['name' => 'date range, last 30 days', 'group' => 'filtered', 'indexOrder' => false, 'run' => $page(['startsAt' => CarbonImmutable::now('UTC')->subDays(30)->startOfDay()->toIso8601String(), 'endsBefore' => CarbonImmutable::now('UTC')->addDay()->startOfDay()->toIso8601String()])];

        $scenarios[] = ['name' => 'title search: river', 'group' => 'search', 'indexOrder' => false, 'run' => $page(['search' => 'river'])];
        $scenarios[] = ['name' => 'title search: lighthouse 5', 'group' => 'search', 'indexOrder' => false, 'run' => $page(['search' => 'lighthouse 5'])];

        $scenarios[] = ['name' => 'categories, page one', 'group' => 'terms', 'indexOrder' => false, 'run' => fn (int $i): array => $this->terms->branches('category', $this->picker->tree('category'), null, null)];
        $scenarios[] = ['name' => 'tags, page one', 'group' => 'terms', 'indexOrder' => false, 'run' => fn (int $i): array => $this->terms->page('tag', null, null)];
        $tagCursor = $this->tagCursor(25_000);
        $scenarios[] = ['name' => 'tags, deep page', 'group' => 'terms', 'indexOrder' => false, 'queriesLike' => 'tags, page one', 'run' => fn (int $i): array => $this->terms->page('tag', $tagCursor, null)];
        $scenarios[] = ['name' => 'tag picker: tag 12', 'group' => 'terms', 'indexOrder' => false, 'run' => fn (int $i): array => $this->picker->search('tag', 'tag 12')];
        $adminUser = User::query()->findOrFail($admin);
        $scenarios[] = ['name' => 'terms: the tabs\' counts', 'group' => 'terms', 'indexOrder' => false, 'run' => fn (int $i): array => app(TermScreen::class)->tabs('category', $adminUser)];

        $library = fn (array $arguments): Closure => fn (int $i): array => $this->library->page(new MediaLibraryRequest(...$arguments));
        $popular = 'M'.str_pad('1', 25, '0', STR_PAD_LEFT);

        $scenarios[] = ['name' => 'media: all, page one', 'group' => 'list', 'indexOrder' => true, 'run' => $library([])];
        $scenarios[] = ['name' => 'media: all, deep page', 'group' => 'list', 'indexOrder' => true, 'queriesLike' => 'media: all, page one', 'run' => $library(['after' => $this->mediaDeepCursor(null)])];
        $scenarios[] = ['name' => 'media: images, deep page', 'group' => 'list', 'indexOrder' => true, 'queriesLike' => 'media: all, page one', 'run' => $library(['type' => 'image', 'after' => $this->mediaDeepCursor('image')])];
        $scenarios[] = ['name' => 'media: video', 'group' => 'list', 'indexOrder' => true, 'run' => $library(['type' => 'video'])];
        $scenarios[] = ['name' => 'media: trash tab', 'group' => 'filtered', 'indexOrder' => true, 'run' => $library(['tab' => 'trash'])];
        $scenarios[] = ['name' => 'media: unused', 'group' => 'filtered', 'indexOrder' => true, 'run' => $library(['unused' => true])];
        $scenarios[] = ['name' => 'media: missing alt text', 'group' => 'filtered', 'indexOrder' => true, 'run' => $library(['missingAlt' => true])];
        $scenarios[] = ['name' => 'media: uploaded for one item', 'group' => 'filtered', 'indexOrder' => false, 'run' => $library(['uploadedFor' => BenchmarkSeeder::itemId(10)])];
        $scenarios[] = ['name' => 'media: mine', 'group' => 'filtered', 'indexOrder' => true, 'run' => $library(['mine' => true, 'viewerId' => $author])];
        $scenarios[] = ['name' => 'media: one month', 'group' => 'filtered', 'indexOrder' => false, 'run' => $library(['month' => date('Y-m')])];
        $scenarios[] = ['name' => 'media: the months', 'group' => 'list', 'indexOrder' => false, 'run' => fn (int $i): array => app(MediaMonths::class)->list(false, 'UTC')];
        $scenarios[] = ['name' => 'media search: harbour', 'group' => 'search', 'indexOrder' => false, 'run' => $library(['search' => 'harbour'])];
        $scenarios[] = ['name' => 'media search: lighthouse 5', 'group' => 'search', 'indexOrder' => false, 'run' => $library(['search' => 'lighthouse 5'])];
        // The picker became the library modal in 1.4c Task 4: MediaPicker::page(MediaLibraryRequest, kinds).
        $scenarios[] = ['name' => 'picker, page one', 'group' => 'list', 'indexOrder' => true, 'run' => fn (int $i): array => $this->mediaPicker->page(new MediaLibraryRequest, ['image'])];
        $scenarios[] = ['name' => 'picker search: river', 'group' => 'search', 'indexOrder' => false, 'run' => fn (int $i): array => $this->mediaPicker->page(new MediaLibraryRequest(search: 'river'), ['image'])];
        $scenarios[] = ['name' => 'used in: most-used image, page one', 'group' => 'filtered', 'indexOrder' => true, 'run' => fn (int $i): array => $this->usages->page($popular, null)];
        $usageCursor = $this->usageDeepCursor($popular);
        $scenarios[] = ['name' => 'used in: most-used image, deep page', 'group' => 'filtered', 'indexOrder' => true, 'queriesLike' => 'used in: most-used image, page one', 'run' => fn (int $i): array => $this->usages->page($popular, $usageCursor)];

        $quickEdited = [];

        foreach ([['quick edit, nothing waiting', false], ['quick edit, draft waiting', true]] as [$name, $waiting]) {
            $items = $this->quickEditItems($runs + 2, $waiting);
            $quickEdited = [...$quickEdited, ...array_column($items, 0)];
            $scenarios[] = [
                'name' => $name,
                'group' => 'quickedit',
                'indexOrder' => false,
                'run' => function (int $i) use ($items, $admin): mixed {
                    [$contentId, $revisionId] = $items[$i];

                    return app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($contentId, $revisionId, $admin, title: "Benchmark retitle {$i}"));
                },
            ];
        }

        return [
            ...$scenarios,
            ...$this->editorScenarios($runs, $admin, $quickEdited),
            ...$this->screenScenarios($runs, $page, $author, $admin, $quickEdited),
            ...$this->publicScenarios($runs, $author),
            ...$this->comments->all($runs, $admin, $this->reader(...)),
            ...$this->seo->all($runs, $admin, $this->reader(...)),
            ...$this->ai->all(),
            ...$this->cache->all($runs, $admin, $quickEdited),
            ...$this->addressScenarios($runs, $admin),
        ];
    }

    /**
     * Addresses (1.6a): finding a path's row, current or old; answering a
     * post's public address; and one recompute batch once the post pattern
     * moves to the year. The recompute runs last and puts the pattern and
     * its posts back afterwards, so no other scenario, in this run or the
     * next, sees them moved.
     *
     * @return list<array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, items?: int, prepare?: Closure(): void, finish?: Closure(): void}>
     */
    private function addressScenarios(int $runs, string $admin): array
    {
        // Picked before timing: ORDER BY random() only locates the rows.
        $picked = fn (bool $current, int $count): array => DB::table('paths')
            ->where('is_current', $current)
            ->orderByRaw('random()')
            ->limit($count)
            ->pluck('path')
            ->map(fn (mixed $path): string => (string) $path)
            ->all();

        $lookups = [...$picked(true, 1000), ...$picked(false, 1000)];

        $posts = DB::table('paths')
            ->join('contents', 'paths.target_id', '=', DB::raw('contents.id::text'))
            ->where('paths.target_type', 'content')
            ->where('paths.is_current', true)
            ->where('contents.type', 'post')
            ->orderByRaw('random()')
            ->limit($runs + 2)
            ->pluck('paths.path')
            ->map(fn (mixed $path): string => Addresses::toUrl((string) $path, 'post'))
            ->all();

        $scenarios = [];

        $scenarios[] = [
            'name' => 'address.lookup',
            'group' => 'address',
            'indexOrder' => false,
            'items' => max(1, count($lookups)),
            'run' => function (int $i) use ($lookups): int {
                foreach ($lookups as $path) {
                    Paths::find($path);
                }

                return count($lookups);
            },
        ];

        $scenarios[] = ['name' => 'public.item', 'group' => 'list', 'indexOrder' => false, 'run' => fn (int $i): array => PathResolver::resolve($posts[$i % count($posts)], '')];

        // Posts still at the root, from the low half of the data set, which
        // no other scenario writes to, one batch per run; a later run takes
        // the next posts, since these have moved.
        $before = '';
        $batches = [];

        $scenarios[] = [
            'name' => 'address.recompute',
            'group' => 'quickedit',
            'indexOrder' => false,
            'items' => RebuildPaths::MOST,
            'prepare' => function () use ($runs, $admin, &$before, &$batches): void {
                $before = AddressSettings::current()->patterns['post'];
                $this->changePostPattern(self::YEAR_PATTERN, $admin);

                $ids = DB::table('contents')
                    ->join('paths', fn ($join) => $join->on('paths.target_id', '=', DB::raw('contents.id::text'))->where('paths.target_type', 'content')->where('paths.is_current', true))
                    ->where('contents.type', 'post')
                    ->where('contents.status', 'published')
                    ->whereNull('contents.deleted_at')
                    ->where('paths.path', 'not like', '%/%')
                    ->whereRaw('substr(contents.id, 2)::bigint % 100 <> 51')
                    ->whereRaw('substr(contents.id, 2)::bigint > 2')
                    ->whereRaw('substr(contents.id, 2)::bigint < (SELECT count(*) / 2 FROM contents)')
                    ->orderBy('contents.id')
                    ->limit(RebuildPaths::MOST * ($runs + 2))
                    ->pluck('contents.id')
                    ->map(fn (mixed $id): string => (string) $id)
                    ->all();

                $batches = array_chunk($ids, RebuildPaths::MOST);
            },
            'run' => function (int $i) use ($admin, &$batches): int {
                return app(RebuildPaths::class)->execute(new RebuildPathsInput('content', $batches[$i] ?? [], $admin));
            },
            'finish' => function () use ($admin, &$before, &$batches): void {
                if ($before === '') {
                    return;
                }

                $this->changePostPattern($before, $admin);

                // The moved posts back to the root, outside the timing, so no
                // run drifts the data set: PathProjection makes each post's
                // root address current again and keeps the year one as history.
                foreach ($batches as $batch) {
                    app(RebuildPaths::class)->execute(new RebuildPathsInput('content', $batch, $admin));
                }
            },
        ];

        return $scenarios;
    }

    /**
     * The public site (1.6h, I-400), each page asked of the HTTP kernel as a
     * reader's request, in a fresh scope (D5), with the default theme and no
     * page cache: the front
     * page, articles, a category, an author at page one and deep, and the
     * site search. Each run is a reader of its own, since one address may
     * search thirty times a minute; any answer but 200 ends the run, since a
     * 404 timed as a page would be the wrong number.
     *
     * @return list<array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, queriesLike?: string}>
     */
    private function publicScenarios(int $runs, int $author): array
    {
        $get = $this->reader(...);

        // Picked before timing: ORDER BY random() only locates the rows.
        $articles = DB::table('paths')
            ->join('contents', 'paths.target_id', '=', DB::raw('contents.id::text'))
            ->where('paths.target_type', 'content')
            ->where('paths.is_current', true)
            ->where('contents.type', 'post')
            // Posts without comments, so the thread adds nothing of its own to
            // this page's pair; the long thread has scenarios of its own.
            ->whereRaw('substr(contents.id, 2)::bigint % 100 <> ?', [BenchmarkSeeder::COMMENTED])
            ->orderByRaw('random()')
            ->limit($runs + 2)
            ->pluck('paths.path')
            ->map(fn (mixed $path): string => Addresses::toUrl((string) $path, 'post'))
            ->all();

        $address = fn (string $type, string $id): string => (string) DB::table('paths')->where('target_type', $type)->where('target_id', $id)->where('is_current', true)->value('path');
        $twentyTags = Addresses::toUrl($address('content', 'C'.str_pad((string) BenchmarkSeeder::TWENTY_TAGS_ITEM, 25, '0', STR_PAD_LEFT)), 'post');
        $category = (string) DB::table('terms')->where('taxonomy', 'category')->orderByDesc('published_count')->orderBy('id')->value('id');
        $categoryUrl = Addresses::toUrl($address('term', $category));
        $authorUrl = Addresses::toUrl($address('user', (string) $author));

        // The year most posts were published in, whose archive counted them all
        // before 1.6h capped its count at the deepest page served.
        $year = (int) DB::table('contents')->where('type', 'post')->where('status', 'published')->whereNull('deleted_at')
            ->selectRaw('extract(year FROM published_at)::int AS year')->groupByRaw('1')->orderByRaw('count(*) DESC')->limit(1)->value('year');

        // Page 400 of the busiest author, or their last page in a smaller data set.
        $authorPosts = DB::table('contents')->where('author_id', $author)->where('type', 'post')->where('status', 'published')->whereNull('deleted_at')->where('published_at', '<=', now())->count();
        $deepPage = min(400, max(1, (int) ceil($authorPosts / app(ListingViews::class)->perPage())));

        return [
            ['name' => 'public: front page', 'group' => 'public-list', 'indexOrder' => true, 'run' => $get('/')],
            ['name' => 'public: article', 'group' => 'public-item', 'indexOrder' => false, 'run' => fn (int $i): int => $get($articles[$i % count($articles)])($i)],
            ['name' => 'public: article, 20 tags', 'group' => 'public-item', 'indexOrder' => false, 'queriesLike' => 'public: article', 'run' => $get($twentyTags)],
            ['name' => 'public: category, page one', 'group' => 'public-list', 'indexOrder' => true, 'run' => $get($categoryUrl)],
            ['name' => 'public: author, page one', 'group' => 'public-list', 'indexOrder' => true, 'run' => $get($authorUrl)],
            ['name' => 'public: year archive', 'group' => 'public-list', 'indexOrder' => true, 'run' => $get(Addresses::toUrl(sprintf('%04d', $year)))],
            ['name' => 'public: author, page '.$deepPage, 'group' => 'public-deep', 'indexOrder' => true, 'queriesLike' => 'public: author, page one', 'run' => $get(Pagination::urlFor($authorUrl, $deepPage))],
            ['name' => 'public: search, rare word', 'group' => 'public-search', 'indexOrder' => false, 'run' => $get('/?s='.BenchmarkSeeder::RARE_WORD)],
            ['name' => 'public: search, common word', 'group' => 'public-search', 'indexOrder' => false, 'run' => $get('/?s='.BenchmarkSeeder::COMMON_WORD)],
            ['name' => 'public: search, two words', 'group' => 'public-search', 'indexOrder' => false, 'run' => $get('/?s='.BenchmarkSeeder::COMMON_WORD.'+harbour')],
            ['name' => 'public: search, mid-frequency words', 'group' => 'public-search', 'indexOrder' => false, 'run' => $get('/?s='.urlencode(self::MIDDLING_WORDS))],
        ];
    }

    /**
     * A public page asked of the HTTP kernel as a reader's request, in a
     * fresh scope, as every production request starts; any answer but 200
     * ends the run, since a 404 timed as a page would be the wrong number.
     *
     * @return Closure(int): int
     */
    private function reader(string $path): Closure
    {
        return function (int $i) use ($path): int {
            // A fresh scope, as every production request starts with (D5):
            // nothing an earlier run read, the settings or an image, is kept.
            app()->forgetScopedInstances();

            $request = Request::create($path, 'GET', server: ['REMOTE_ADDR' => '198.51.100.'.($i % 250 + 1)]);
            $kernel = app(HttpKernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException("The public page {$path} answered {$response->getStatusCode()}.");
            }

            return $response->getStatusCode();
        };
    }

    /**
     * Sets the post pattern through ChangeAddresses, as the screen does, but
     * with the recompute it queues discarded: the timed batches move their
     * posts themselves, and every other post stays where it is.
     */
    private function changePostPattern(string $pattern, string $admin): void
    {
        $current = AddressSettings::current();
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
            app(ChangeAddresses::class)->execute(new ChangeAddressesInput(
                ['post' => $pattern], $current->bases, $current->authorBase, $current->frontShows, $current->frontPageId, $current->postsPageId, $admin,
            ));
        } finally {
            config(['queue.default' => $queue, 'queue.connections.database-long' => $long]);
            app()->instance('queue', $manager);
        }
    }

    /**
     * The editor (1.5c to 1.5e): opening it, its history to the deepest
     * page, an autosave and a checkpoint on fresh items, comparing two long
     * revisions, and the body search its published text feeds.
     *
     * @param  list<string>  $quickEdited
     *
     * @return list<array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, queriesLike?: string, items?: int}>
     */
    private function editorScenarios(int $runs, string $admin, array $quickEdited): array
    {
        $item = fn (int $n): string => 'C'.str_pad((string) $n, 25, '0', STR_PAD_LEFT);
        $adminUser = User::query()->findOrFail($admin);
        // Item 51's autosave is its author's, so it opens as them.
        $autosaver = (string) DB::table('content_autosaves')->where('content_id', $item(51))->value('user_id');
        $scenarios = [];

        $scenarios[] = ['name' => 'editor.open', 'group' => 'editor', 'indexOrder' => false, 'run' => fn (int $i): ?EditorSession => EditorSession::for($item(151), $admin)];
        $scenarios[] = ['name' => 'editor.open_with_autosave', 'group' => 'editor', 'indexOrder' => false, 'run' => fn (int $i): ?EditorSession => EditorSession::for($item(51), $autosaver)];

        // Page five of the deep article's 113 revisions: page four's cursor,
        // worked out before the runs.
        $cursor = null;

        foreach (range(1, 4) as $page) {
            $cursor = RevisionHistory::for($item(1), $adminUser, $cursor)?->older;
        }

        $scenarios[] = ['name' => 'editor.history', 'group' => 'editor', 'indexOrder' => true, 'run' => fn (int $i): mixed => RevisionHistory::for($item(1), $adminUser)];
        $scenarios[] = ['name' => 'editor.history_deep', 'group' => 'editor', 'indexOrder' => true, 'queriesLike' => 'editor.history', 'run' => fn (int $i): mixed => RevisionHistory::for($item(1), $adminUser, $cursor)];

        $autosaved = $this->freshItems($runs + 2, 0, $runs, $quickEdited);
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'An edit the benchmark typed.']]]]];
        $autosave = fn (array $pointer, string $contentId, int $i): mixed => app(SaveAutosave::class)->execute(new SaveAutosaveInput(
            $contentId, "Benchmark autosave {$i}", $document, [], $pointer['terms'], $pointer['workingRevisionId'], $admin,
        ));

        $scenarios[] = ['name' => 'editor.autosave', 'group' => 'quickedit', 'indexOrder' => false, 'run' => fn (int $i): mixed => $autosave($autosaved[$i][1], $autosaved[$i][0], $i)];

        // A checkpoint writes the person's autosave, so each item gets one
        // first, untimed.
        $checkpointed = $this->freshItems($runs + 2, 1, $runs, $quickEdited);

        foreach ($checkpointed as $i => [$contentId, $pointer]) {
            $autosave($pointer, $contentId, $i);
        }

        $scenarios[] = ['name' => 'editor.checkpoint', 'group' => 'quickedit', 'indexOrder' => false, 'run' => fn (int $i): mixed => app(CheckpointDraft::class)->execute(new CheckpointDraftInput($checkpointed[$i][0], $admin))];

        // The long article's two newest revisions, read before the runs.
        $long = DB::table('content_revisions')->whereIn('id', ['R'.str_pad('5', 25, '0', STR_PAD_LEFT), 'R'.str_pad('6', 25, '0', STR_PAD_LEFT)])->orderBy('id')->pluck('document')
            ->map(fn (mixed $document): array => (array) json_decode((string) $document, true))->all();

        $scenarios[] = ['name' => 'editor.compare', 'group' => 'search', 'indexOrder' => false, 'run' => fn (int $i): array => app(DocumentDiff::class)->between($long[0], $long[1])];

        $body = fn (string $words): Closure => fn (int $i): array => $this->list->page(new ContentListRequest(type: 'post', search: $words, searchIn: 'body'));
        $scenarios[] = ['name' => 'search.body_one_word', 'group' => 'search', 'indexOrder' => false, 'run' => $body('harbour')];
        $scenarios[] = ['name' => 'search.body_rare_word', 'group' => 'search', 'indexOrder' => false, 'run' => $body(BenchmarkSeeder::RARE_WORD)];
        $scenarios[] = ['name' => 'search.body_phrase', 'group' => 'search', 'indexOrder' => false, 'run' => $body('harbour lighthouse')];
        $scenarios[] = ['name' => 'palette.mixed', 'group' => 'search', 'indexOrder' => false, 'run' => fn (int $i): array => app(AdminSearch::class)->run('har', $adminUser)];

        return $scenarios;
    }

    /**
     * The scheduler's pass (1.5e), the dashboard (1.5g), and the lists' new
     * sorts, filter and Bulk Edit (1.5f, 1.5h).
     *
     * @param  Closure(array<string, mixed>): Closure  $page
     * @param  list<string>  $quickEdited
     *
     * @return list<array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, queriesLike?: string, items?: int, finish?: Closure(): void}>
     */
    private function screenScenarios(int $runs, Closure $page, int $author, string $admin, array $quickEdited): array
    {
        $scenarios = [];

        $this->dueSchedules(50 * ($runs + 2), $admin);
        $scenarios[] = ['name' => 'scheduler.claim', 'group' => 'scheduler', 'indexOrder' => false, 'run' => fn (int $i): int => Artisan::call('teme:content:run-due-publications')];

        $editor = User::query()->where('email', 'editor@bench.test')->firstOrFail();
        $scenarios[] = ['name' => 'dashboard.open', 'group' => 'list', 'indexOrder' => false, 'run' => fn (int $i): array => app(Dashboard::class)->forUser($editor)];

        $contributor = (int) DB::table('users')->where('email', 'author3@bench.test')->value('id');
        $scenarios[] = ['name' => 'list.contributor_all', 'group' => 'list', 'indexOrder' => true, 'queriesLike' => 'all tab, by updated, page one', 'run' => $page(['tab' => 'all', 'viewerId' => $contributor])];

        $scenarios[] = ['name' => 'list.sort_author', 'group' => 'filtered', 'indexOrder' => true, 'run' => $page(['sort' => 'author', 'direction' => 'asc'])];
        $scenarios[] = ['name' => 'list.sort_author_deep', 'group' => 'filtered', 'indexOrder' => true, 'queriesLike' => 'list.sort_author', 'run' => $page(['sort' => 'author', 'direction' => 'asc', 'after' => $this->deepCursor('all', $author, 'author_sort', 'asc')])];

        // Last month, as UTC bounds; the site's zone is the controller's to apply.
        $month = CarbonImmutable::now('UTC')->startOfMonth()->subMonthNoOverflow();
        $scenarios[] = ['name' => 'list.month', 'group' => 'filtered', 'indexOrder' => false, 'run' => $page([
            'tab' => 'published', 'sort' => 'published',
            'startsAt' => $month->toIso8601String(), 'endsBefore' => $month->addMonthNoOverflow()->toIso8601String(),
        ])];

        // One batch of fresh items per run, as one Bulk Edit request sends it:
        // a tag added and the author changed on each, timed per item.
        $bulk = $this->freshItems(self::BULK_BATCH * ($runs + 2), 2, $runs, $quickEdited);
        $tag = 'T'.str_pad('2002', 25, '0', STR_PAD_LEFT);
        $newAuthor = (int) DB::table('users')->where('email', 'author5@bench.test')->value('id');

        $scenarios[] = [
            'name' => 'bulk.edit, per item (batch of 25)',
            'group' => 'quickedit',
            'indexOrder' => false,
            'items' => self::BULK_BATCH,
            'run' => function (int $i) use ($bulk, $admin, $tag, $newAuthor): int {
                $ids = array_column(array_slice($bulk, $i * self::BULK_BATCH, self::BULK_BATCH), 0);

                // The batch's person, read once, as BulkContentController keeps its request's (B-201).
                app(Actors::class)->keep($admin);

                try {
                    foreach ($this->pointers->forIds($ids) as $id => $pointer) {
                        app(BulkEditItem::class)->apply((string) $id, $pointer, $admin, [$tag], [], $newAuthor, 'keep');
                    }
                } finally {
                    app(Actors::class)->forget();
                }

                return count($ids);
            },
        ];

        // Fresh posts again, past Bulk Edit's, closed through the Discussion
        // step alone, a batch a run, timed per item; opened again after, as
        // the seeder wrote them, so the next run finds them as this one did.
        $closing = $this->freshItems(self::BULK_BATCH * ($runs + 2), 2 + self::BULK_BATCH, $runs, $quickEdited);

        $scenarios[] = [
            'name' => 'bulk.edit, discussion, per item (batch of 25)',
            'group' => 'quickedit',
            'indexOrder' => false,
            'items' => self::BULK_BATCH,
            'run' => function (int $i) use ($closing, $admin): int {
                $ids = array_column(array_slice($closing, $i * self::BULK_BATCH, self::BULK_BATCH), 0);

                app(Actors::class)->keep($admin);

                try {
                    foreach ($this->pointers->forIds($ids) as $id => $pointer) {
                        app(BulkEditItem::class)->apply((string) $id, $pointer, $admin, [], [], null, 'keep', 'closed');
                    }
                } finally {
                    app(Actors::class)->forget();
                }

                return count($ids);
            },
            'finish' => function () use ($closing): void {
                DB::table('contents')->whereIn('id', array_column($closing, 0))->update(['comment_status' => 'open']);
            },
        ];

        return $scenarios;
    }

    /**
     * Published, live posts no other scenario touches, from the middle of the
     * data set upward: slice 0 for autosaves, 1 for checkpoints, 2 for Bulk
     * Edit, each $runs + 2 apart so they never overlap, and each item fresh,
     * so no run times a row an earlier one warmed. Items with a deep history,
     * an autosave or the long text are left out.
     *
     * @param  list<string>  $exclude  the quick-edit scenarios' items
     *
     * @return list<array{0: string, 1: array{type: string, status: string, workingRevisionId: string|null, terms: list<string>}}>
     */
    private function freshItems(int $count, int $slice, int $runs, array $exclude): array
    {
        $ids = DB::table('contents')
            ->where('type', 'post')
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereRaw('substr(id, 2)::bigint % 100 <> 51')
            ->whereRaw('substr(id, 2)::bigint > 2')
            ->whereRaw('substr(id, 2)::bigint >= (SELECT count(*) / 2 FROM contents)')
            ->whereNotIn('id', $exclude)
            ->orderBy('id')
            ->offset($slice * ($runs + 2))
            ->limit($count)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $pointers = $this->pointers->forIds($ids);

        return array_map(fn (string $id): array => [$id, $pointers[$id]], $ids);
    }

    /**
     * Up to $count due schedules, run_at in the past, for scheduler.claim:
     * each on its own unpublished post with no pending schedule, which no
     * other scenario writes to, scheduled by the administrator, with
     * scheduled_for kept equal to run_at (1.5e). A small data set may have
     * fewer such posts; each pass then takes what is left.
     */
    private function dueSchedules(int $count, string $admin): void
    {
        DB::statement(<<<'SQL'
            WITH chosen AS (
                SELECT c.id, c.working_revision_id, now() - make_interval(mins => (row_number() OVER (ORDER BY c.id))::int) AS run_at
                FROM contents c
                WHERE c.type = 'post' AND c.status IN ('draft', 'scheduled') AND c.deleted_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM scheduled_publications s WHERE s.content_id = c.id AND s.completed_at IS NULL AND s.failed_at IS NULL AND s.cancelled_at IS NULL)
                  AND NOT EXISTS (SELECT 1 FROM content_reviews r WHERE r.content_id = c.id)
                ORDER BY c.id
                LIMIT ?
            ), scheduled AS (
                INSERT INTO scheduled_publications (id, content_id, revision_id, action, run_at, created_by, attempts, created_at, updated_at)
                SELECT 'D' || substr(id, 2), id, working_revision_id, 'publish', run_at, ?::bigint, 0, now(), now() FROM chosen
            )
            UPDATE contents c SET scheduled_for = chosen.run_at FROM chosen WHERE c.id = chosen.id
            SQL, [$count, (int) $admin]);
    }

    /**
     * The cursor for a page about halfway down a tab's list. The one place
     * OFFSET appears: it only locates a starting row, and is never timed.
     */
    private function deepCursor(string $tab, int $authorId, string $column, string $direction): string
    {
        $query = fn (): Builder => DB::table('contents')
            ->where('type', 'post')
            ->whereNull('deleted_at')
            ->when($tab === 'published', fn (Builder $q) => $q->where('status', 'published'))
            ->when($tab === 'drafts', fn (Builder $q) => $q->where('status', 'draft'))
            ->when($tab === 'mine', fn (Builder $q) => $q->where('author_id', $authorId));

        $offset = max(0, min(500_000, intdiv($query()->count(), 2)));
        $row = $query()->orderBy($column, $direction)->orderBy('id', $direction)->offset($offset)->limit(1)->first([$column, 'id']);

        return Cursor::encode([$row->{$column}, $row->id]);
    }

    private function tagCursor(int $offset): string
    {
        $row = DB::table('terms')->where('taxonomy', 'tag')->orderBy('name')->orderBy('id')->offset($offset)->limit(1)->first(['name', 'id']);

        return Cursor::encode([$row->name, $row->id]);
    }

    /**
     * The cursor for a library page about halfway down, newest first. OFFSET
     * only locates the starting row, and is never timed.
     */
    private function mediaDeepCursor(?string $kind): string
    {
        $query = fn (): Builder => DB::table('media')->whereNull('deleted_at')->when($kind !== null, fn (Builder $q) => $q->where('kind', $kind));
        $row = $query()->orderByDesc('created_at')->orderByDesc('id')->offset(intdiv($query()->count(), 2))->limit(1)->first(['created_at', 'id']);

        return Cursor::encode([$row->created_at, $row->id]);
    }

    /**
     * The cursor for a page halfway through an item's uses.
     */
    private function usageDeepCursor(string $mediaId): string
    {
        $contentIds = DB::table('media_usages')->where('media_id', $mediaId)->distinct()->orderBy('content_id')->pluck('content_id');

        return Cursor::encode([(string) $contentIds[intdiv($contentIds->count(), 2)]]);
    }

    /**
     * Published, live items to retitle — with nothing waiting, or with a draft
     * waiting (their ID is divisible by 3) — starting halfway through the data
     * set, one per run, so no run times a row an earlier one warmed.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function quickEditItems(int $count, bool $waiting): array
    {
        $ids = DB::table('contents')
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereRaw('substr(id, 2)::bigint % 3 '.($waiting ? '=' : '<>').' 0')
            ->orderByRaw('abs(substr(id, 2)::bigint - (SELECT count(*) / 2 FROM contents))')
            ->limit($count)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $pointers = $this->pointers->forIds($ids);

        return array_map(fn (string $id): array => [$id, (string) $pointers[$id]['workingRevisionId']], $ids);
    }

    /**
     * Every SELECT the scenario runs, planned with EXPLAIN and checked, and
     * counted — the same run the query-plan check already makes, so check 4
     * (a fixed number of queries per request) costs nothing extra — with the
     * statements it wrote and the commands it sent Valkey counted beside
     * them (D6).
     *
     * @param  Closure(int): mixed  $run
     * @param  array<string, int>  $sizes
     *
     * @return array{problems: list<string>, queries: int, writes: int, commands: int, indexes: list<string>}
     */
    private function inspect(Closure $run, int $iteration, array $sizes, bool $indexOrder, bool $planWrites = false): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->tally->start();
        $run($iteration);
        $this->tally->stop();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $problems = [];
        $indexes = [];
        $settings = [];
        $queries = 0;

        foreach ($log as ['query' => $sql, 'bindings' => $bindings]) {
            // The dense list plan runs its rows query under SET LOCAL
            // settings that end with its transaction, so they apply to the
            // one SELECT that followed them and to no other.
            if (preg_match('/^\s*set\s+local\b/i', $sql) === 1) {
                $settings[] = $sql;

                continue;
            }

            // A scenario may have its writes planned too: EXPLAIN without
            // ANALYZE runs nothing, and only SELECTs count as queries.
            $write = $planWrites && preg_match('/^\s*(insert|update|delete)\b/i', $sql) === 1;

            if (! $write && preg_match('/^\s*(select|with)\b/i', $sql) !== 1) {
                continue;
            }

            if (! $write) {
                $queries++;
            }

            $row = DB::transaction(function () use ($settings, $sql, $bindings): object {
                foreach ($settings as $setting) {
                    DB::statement($setting);
                }

                return DB::select('EXPLAIN (FORMAT JSON) '.$sql, $bindings)[0];
            });

            $settings = [];

            /** @var array<int, array<string, mixed>> $plan */
            $plan = json_decode((string) $row->{'QUERY PLAN'}, true);

            foreach ($this->inspector->problems($plan, $sizes, $indexOrder) as $problem) {
                $problems[$problem] = $problem;
            }

            foreach ($this->inspector->indexes($plan) as $index) {
                $indexes[$index] = $index;
            }
        }

        return ['problems' => array_values($problems), 'queries' => $queries, 'writes' => $this->tally->writes(), 'commands' => $this->tally->commands(), 'indexes' => array_values($indexes)];
    }

    /**
     * @return array<string, int>
     */
    private function tableSizes(): array
    {
        return DB::table('pg_class')
            ->whereIn('relname', self::TABLES)
            ->pluck('reltuples', 'relname')
            ->map(fn (mixed $rows): int => (int) $rows)
            ->all();
    }
}
