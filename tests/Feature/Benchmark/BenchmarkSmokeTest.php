<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Benchmark\BenchmarkRunner;
use App\Domain\Benchmark\BenchmarkSeeder;
use App\Domain\Benchmark\ScenarioResult;
use App\Domain\Contents\ContentCounts;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\MediaCounts;
use App\Domain\Redirects\AddressCounts;
use App\Domain\Redirects\NotFoundLogSection;
use App\Domain\Redirects\RedirectProposal;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SocialCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('seeds a small data set with the right shape', function (): void {
    $n = 500;
    app(BenchmarkSeeder::class)->seed($n);

    // Three per item, 40 more on each item n % 100 = 51, and 110 more on
    // the deep article: 1,810 at 500 items.
    expect(DB::table('contents')->count())->toBe($n)
        ->and(DB::table('content_revisions')->count())->toBe(3 * $n + 40 * intdiv($n + 49, 100) + 110)
        // Written by the INSERTs themselves: each row's author sort is its
        // author's name as AuthorSort folds it, and the bodies differ.
        ->and(DB::table('contents')->join('users', 'users.id', '=', 'contents.author_id')->whereRaw('contents.author_sort <> left(lower(trim(users.name)), 255)')->count())->toBe(0)
        ->and(DB::table('content_revisions')->distinct()->count('plain_text'))->toBeGreaterThan($n)
        ->and(DB::table('terms')->where('taxonomy', 'category')->count())->toBe(2000)
        ->and(DB::table('terms')->where('taxonomy', 'tag')->count())->toBe(50000)
        ->and(ContentCounts::for('post'))->toBe(lifecycleTruth('post'))
        ->and((int) DB::table('terms')->sum('published_count'))
        ->toBe(DB::table('content_terms')->where('scope', 'published')->count())
        ->and(DB::table('media')->count())->toBe(500)
        ->and(MediaCounts::all())->toBe(benchMediaTruth())
        ->and((int) DB::table('media')->sum('working_uses'))->toBe(DB::table('media_usages')->where('scope', 'working')->count())
        ->and(DB::table('media_usages')->where('media_id', 'M'.str_pad('1', 25, '0', STR_PAD_LEFT))->where('scope', 'working')->count())->toBe(5);

    // Each published, live item at its slug, and every tenth with an old
    // address beside it, as the seeder writes them without an Operation;
    // the archives have addresses of their own (1.6h).
    $live = fn () => DB::table('contents')->where('status', 'published')->whereNull('deleted_at');

    expect(DB::table('paths')->where('target_type', 'content')->where('is_current', true)->count())->toBe($live()->count())
        ->and(DB::table('paths')->join('contents', 'contents.id', '=', 'paths.target_id')->where('paths.is_current', true)->whereColumn('paths.path', '<>', 'contents.slug')->count())->toBe(0)
        ->and(DB::table('paths')->where('is_current', false)->count())->toBe($live()->whereRaw('substr(id, 2)::bigint % 10 = 1')->count());

    // The words the site search scenarios look for (1.6h): at 500 items,
    // kittiwake is in item 7 alone, a published post, and tidewater in every
    // published item n % 10 = 3.
    expect(DB::table('contents')->whereRaw("search_tsv @@ to_tsquery('english', 'kittiwake')")->pluck('id')->all())->toBe(['C'.str_pad('7', 25, '0', STR_PAD_LEFT)])
        ->and(DB::table('contents')->whereRaw("search_tsv @@ to_tsquery('english', 'tidewater')")->count())
        ->toBe(DB::table('contents')->whereNotNull('search_tsv')->whereRaw('substr(id, 2)::bigint % 10 = 3')->count());
});

it('runs every scenario and reports a time for each, the page cache\'s with it on and the rest with it off, and leaves nothing of it behind', function (): void {
    app(BenchmarkSeeder::class)->seed(500);
    $folder = cacheOn();
    $runner = app(BenchmarkRunner::class);

    $results = $runner->run(2);
    $byName = collect($results)->keyBy('name');

    expect($results)->not->toBeEmpty()
        ->and($byName->keys()->all())->toContain(
            'address.lookup', 'address.recompute', 'public.item', 'public: front page', 'public: search, rare word',
            'cache: hit, article', 'cache: hit, front page', 'cache: hit, search, common word', 'cache: hit, article, 20 tags, with a CDN',
            'cache: miss, article', 'cache: miss, not found', 'cache: miss, front page', 'cache: stale, category page', 'cache: wait, author page',
            'cache: quick edit', 'cache: quick edit, with a CDN', 'cache: quick edit, with warming',
            'cache: miss, article, static driver', 'cache: quick edit, static driver', 'cache: prune, static driver',
        );

    foreach ($results as $result) {
        expect($result->p95Ms)->toBeGreaterThan(0.0)
            ->and($result->budgetMs)->toBeIn([2, 10, 100, 150, 200, 300, 500]);
    }

    // A hit reads nothing from the database (D8); a miss writes its page's
    // rows, and with the static-file driver its file's row as well.
    foreach (['cache: hit, article', 'cache: hit, front page', 'cache: hit, search, common word', 'cache: hit, article, 20 tags, with a CDN'] as $hit) {
        expect($byName[$hit]->queries + $byName[$hit]->writes)->toBe(0)
            ->and($byName[$hit]->problems)->toBe([]);
    }

    expect($byName['cache: miss, article']->writes)->toBeGreaterThan(0)
        ->and($byName['cache: miss, not found']->writes)->toBeGreaterThan(0)
        ->and($byName['cache: miss, article, static driver']->writes)->toBeGreaterThan($byName['cache: miss, article']->writes)
        ->and(collect($runner->notes())->contains(fn (string $note): bool => str_starts_with($note, 'cache: hit, article, 20 tags, with a CDN: ')))->toBeTrue();

    // The Redis store's scenarios run where a Valkey answers, and CI requires one (D10).
    if (getenv('TEME_REQUIRE_VALKEY') !== false || $byName->has('cache: hit, article (Redis)')) {
        expect($byName['cache: hit, article (Redis)']->queries + $byName['cache: hit, article (Redis)']->writes)->toBe(0)
            ->and($byName['cache: hit, article (Redis)']->commands)->toBe(1);
    } else {
        expect(implode("\n", $runner->notes()))->toContain('The Redis store: not measured.');
    }

    // The recompute's batches moved posts under the year pattern, then put
    // the settings and the posts back, so the next run finds them as it began.
    expect(DB::table('paths')->where('target_type', 'content')->where('is_current', true)->where('path', 'like', '%/%')->count())->toBe(0)
        ->and(AddressSettings::current()->patterns['post'])->toBe('/{slug}/');

    // Nothing of the page cache left behind (D7), and the switch put back (I-460).
    expect(DB::table('page_cache_tags')->count())->toBe(0)
        ->and(DB::table('page_cache_purges')->count())->toBe(0)
        ->and(DB::table('page_cache_files')->count())->toBe(0)
        ->and(DB::table('page_cache_cdn_purges')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(config('page_cache.enabled'))->toBeTrue()
        ->and(config('page_cache.driver'))->toBe('file');

    cacheOff($folder);
});

it('counts queries and writes, starts each public request afresh, and fails a scenario whose deep page costs more than page one', function (): void {
    app(BenchmarkSeeder::class)->seed(500);

    $results = collect(app(BenchmarkRunner::class)->run(1))->keyBy('name');

    expect($results['all tab, by updated, page one']->queries)->toBeGreaterThan(0)
        ->and($results['all tab, by updated, page one']->writes)->toBe(0)
        ->and($results['quick edit, nothing waiting']->writes)->toBeGreaterThan(0)
        ->and($results['all tab, by updated, deep page']->queries)->toBe($results['all tab, by updated, page one']->queries)
        ->and(collect($results['all tab, by updated, deep page']->problems)->filter(fn (string $p): bool => str_contains($p, 'queries, but')))->toBeEmpty();

    // Each public request starts in a fresh scope (D5), so the front page's
    // SELECTs are what a request of its own runs: nothing is remembered from
    // the runs before it, its cards' images included (issue 30). Drawn once
    // first, since the address recompute's settings changes made the cache
    // store forget that group, as a running site's first request refills it.
    addrGet('/')->assertOk();
    app()->forgetScopedInstances();
    $own = collect(dashQueries(fn () => addrGet('/')->assertOk()))->filter(fn (string $sql): bool => preg_match('/^\s*(select|with)\b/i', $sql) === 1)->count();

    expect($results['public: front page']->queries)->toBe($own);
});

it('plans the recount with the seeded rows counted, never for an empty table', function (): void {
    // Earlier tests leave pages of dead rows in terms, which an analyze
    // records as a table of no rows. Planned on that, the recount nests
    // loops over every seeded term and stalls the suite for minutes.
    DB::statement("INSERT INTO terms (id, taxonomy, name, slug, fields, created_at, updated_at) SELECT lower(lpad(g::text, 26, '0')), 'tag', 'x'||g, 'x'||g, '{}', now(), now() FROM generate_series(1, 5000) g");
    DB::statement('DELETE FROM terms');
    DB::statement('ANALYZE terms');

    $plans = [];

    DB::beforeExecuting(function (string $query) use (&$plans): void {
        if (! str_starts_with(ltrim($query), 'UPDATE terms t SET')) {
            return;
        }

        $plan = implode("\n", array_map(fn (object $row): string => (string) $row->{'QUERY PLAN'}, DB::select('EXPLAIN '.$query)));
        $plans[] = $plan;

        // Fail at once rather than run the statement for minutes.
        if (str_contains($plan, 'Nested Loop')) {
            throw new RuntimeException("The recount planned a nested loop:\n{$plan}");
        }
    });

    app(BenchmarkSeeder::class)->seed(50);

    expect($plans)->not->toBeEmpty();
});

it('seeds the comments: a long thread ten deep on one post, the rest spread over others by status, addresses only within keep_ip_days (docs/studies/comments.md §3.11)', function (): void {
    app(BenchmarkSeeder::class)->seed(500, comments: 2000);

    $thread = BenchmarkSeeder::itemId(BenchmarkSeeder::THREAD_ITEM);
    $others = DB::table('comments')->where('content_id', '<>', $thread);

    // Every eighth comment is the thread's: 250, five blocks of 41
    // conversations and a chain of nine replies under the last.
    expect(DB::table('comments')->count())->toBe(2000)
        ->and(DB::table('comments')->where('content_id', $thread)->count())->toBe(250)
        ->and(DB::table('comments')->where('content_id', $thread)->where('depth', 1)->count())->toBe(205)
        ->and((int) DB::table('comments')->where('content_id', $thread)->max('depth'))->toBe(10)
        ->and((int) DB::table('comment_stats')->where('content_id', $thread)->value('thread_count'))->toBe(205)
        ->and((clone $others)->select('status')->selectRaw('count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn (mixed $n): int => (int) $n)->sortKeys()->all())
        ->toBe(['approved' => 1200, 'pending' => 50, 'spam' => 400, 'trash' => 100])
        ->and((clone $others)->distinct()->count('content_id'))->toBe(4)
        // An import leaves out addresses older than keep_ip_days (I-270).
        ->and(DB::table('comments')->whereNotNull('ip')->count())->toBeGreaterThan(0)
        ->and(DB::table('comments')->whereNotNull('ip')->count())->toBe(DB::table('comments')->where('created_at', '>', now()->subDays(60))->count())
        // Each type's start: posts open, pages closed.
        ->and(DB::table('contents')->where('type', 'post')->where('comment_status', '<>', 'open')->count())->toBe(0)
        ->and(DB::table('contents')->where('type', 'page')->where('comment_status', '<>', 'closed')->count())->toBe(0);
});

it('runs the comment scenarios, and puts back every comment, address and Discussion they changed', function (): void {
    app(BenchmarkSeeder::class)->seed(500, comments: 2000);
    $comments = DB::table('comments')->count();
    $addresses = DB::table('comments')->whereNotNull('ip')->count();

    $results = collect(app(BenchmarkRunner::class)->run(1))->keyBy('name');
    $deletion = $results['comments: delete permanently, per comment (Empty spam, 25 a run)'];

    expect($results->keys()->all())->toContain(
        'public: article, long thread', 'public: link to its last comment', 'public: comments, page 2', 'public: comments, page 5',
        'comments: pending, page one', 'comments: pending, deep page', 'comments: view counts',
        'comments: menu badge, an editor', 'comments: menu badge, an author', 'comments: what Empty spam covers',
        'comments: trash purge, one chunk of 1,000', 'comments: delete permanently, per comment (Empty spam, 25 a run)',
        'comments: erase addresses, the daily run', 'bulk.edit, discussion, per item (batch of 25)',
    )
        ->and($deletion->p95Ms)->toBeGreaterThan(0.0)
        ->and($deletion->writes)->toBeGreaterThan(0)
        ->and($results['comments: erase addresses, the daily run']->writes)->toBeGreaterThan(0)
        ->and(collect([$results['comments: pending, deep page'], $results['public: comments, page 5']])
            ->flatMap(fn (ScenarioResult $result): array => $result->problems)
            ->filter(fn (string $problem): bool => str_contains($problem, 'queries, but'))
            ->all())->toBe([]);

    // The spam Empty spam was given is gone, the addresses the erasure was
    // given are erased again, and every post is open again.
    expect(DB::table('comments')->count())->toBe($comments)
        ->and(DB::table('comments')->whereNotNull('ip')->count())->toBe($addresses)
        ->and(DB::table('contents')->where('type', 'post')->where('comment_status', '<>', 'open')->count())->toBe(0);
});

it('seeds redirects a save would store, a full 404 log, and an article whose head takes every fallback (docs/studies/seo.md §3.15)', function (): void {
    app(BenchmarkSeeder::class)->seed(500, redirects: 1000, notFound: 1000);

    $kinds = DB::table('redirects')->select('kind')->selectRaw('count(*) AS n')->groupBy('kind')->pluck('n', 'kind')->map(fn (mixed $n): int => (int) $n)->sortKeys()->all();

    expect($kinds)->toBe(['exact' => 900, 'pattern' => 100])
        ->and(DB::table('redirects')->where('kind', 'exact')->whereNull('target_kind')->count())->toBe(9)
        ->and(DB::table('redirects')->where('status', 302)->count())->toBe(45)
        ->and(DB::table('not_found_log')->count())->toBe(1000)
        ->and(DB::table('not_found_log')->distinct()->count('count'))->toBeGreaterThan(100);

    // One of each kind read back as a save reads it: the stored row is the one a person's would be.
    $typed = fn (object $row): array => match ($row->target_kind) {
        'content' => ['', $row->target, null],
        'media' => ['', null, $row->target],
        null => ['', null, null],
        default => [(string) $row->target, null, null],
    };
    $columns = ['source', 'kind', 'pattern', 'status', 'target_kind', 'target', 'target_path', 'note'];

    foreach (['address', 'outside', 'content', 'media', null] as $kind) {
        $row = DB::table('redirects')->where('kind', 'exact')->where('target_kind', $kind)->orderBy('id')->first($columns);
        [$to, $item, $file] = $typed($row);

        expect(RedirectProposal::read('/'.$row->source, (int) $row->status, $to, $item, $file, '')->columns())->toEqual((array) $row);
    }

    $pattern = DB::table('redirects')->where('kind', 'pattern')->orderBy('id')->first($columns);

    expect(RedirectProposal::read('/'.$pattern->source, 301, (string) $pattern->target, null, null, '')->columns())->toEqual((array) $pattern);

    // Every item a redirect sends readers to is live, so each answers.
    $targets = DB::table('redirects')->where('target_kind', 'content')->distinct()->pluck('target');

    expect(DB::table('contents')->whereIn('id', $targets)->where('status', 'published')->whereNull('deleted_at')->count())->toBe($targets->count());

    // The article: an excerpt and a canonical naming a trashed page, and nothing else of its own, on the item and on what it shows.
    $article = BenchmarkSeeder::itemId(BenchmarkSeeder::FALLBACK_ITEM);
    $fields = [BuiltInFields::EXCERPT => 'A story told through every fallback the head has.', BuiltInFields::CANONICAL => ['item' => BenchmarkSeeder::itemId(BenchmarkSeeder::TRASHED_PAGE)]];
    $shown = (string) DB::table('content_revisions')->where('id', DB::table('contents')->where('id', $article)->value('published_revision_id'))->value('fields');

    expect(json_decode((string) DB::table('contents')->where('id', $article)->value('fields'), true))->toEqual($fields)
        ->and(json_decode($shown, true))->toEqual($fields)
        ->and(DB::table('contents')->where('id', BenchmarkSeeder::itemId(BenchmarkSeeder::TRASHED_PAGE))->whereNotNull('deleted_at')->exists())->toBeTrue()
        ->and(DB::table('paths')->where('target_type', 'content')->where('target_id', BenchmarkSeeder::itemId(BenchmarkSeeder::TRASHED_PAGE))->where('is_current', true)->exists())->toBeFalse();
});

it('runs the redirect, 404, sitemap and head scenarios, writes nothing on a reader\'s request, and puts back every setting, log row and spool line', function (): void {
    app(BenchmarkSeeder::class)->seed(500, redirects: 1000, notFound: 1000);
    $log = DB::table('not_found_log')->orderBy('path')->pluck('path')->all();
    // A folder of this test's own: the default one is every parallel worker's,
    // and another worker's redirect or 404 writes its counts there (issue 106).
    $site = sys_get_temp_dir().'/teme-benchmark-'.getmypid().'-'.uniqid();
    config(['page_cache.stores.file.path' => $site]);
    $runner = app(BenchmarkRunner::class);

    $results = collect($runner->run(1))->keyBy('name');

    expect($results->keys()->all())->toContain(
        'redirect.lookup: exact', 'redirect.lookup: none, every pattern tried',
        'public: redirect, to an address', 'public: redirect, to an item',
        'redirects: page one', 'redirects: halfway down', 'redirects: the tabs\' counts',
        'redirects: search, a word every source holds', 'redirects: search, one source',
        '404 log: page one', '404 log: halfway down',
        'public: not found, a new address each request', '404 log: the minute\'s run, a full log of new addresses',
        'public: sitemap index, its pages found afresh', 'public: sitemap, posts page one',
        'public: article, every fallback in play',
    );

    // Each request for a new address writes nothing; the minute's run writes them all. Which
    // index a plan reads on tables this small is the planner's coin toss, so `reads` is the
    // run at scale's to judge (Task 5), never this test's.
    expect($results['public: not found, a new address each request']->writes)->toBe(0)
        ->and($results['404 log: the minute\'s run, a full log of new addresses']->writes)->toBeGreaterThan(0)
        ->and($results['public: redirect, to an item']->queries)->toBeGreaterThan($results['public: redirect, to an address']->queries);

    // The log is as seeded, Search and social as it was, and no line reached the site's spool.
    $overrides = (array) json_decode((string) (DB::table('settings')->where('group_key', SearchAndSocial::KEY)->value('overrides') ?? '{}'), true);

    expect(DB::table('not_found_log')->orderBy('path')->pluck('path')->all())->toBe($log)
        ->and(array_intersect(array_keys($overrides), [NotFoundLogSection::SIZE, SocialCards::DEFAULT_IMAGE, 'publisher_type', 'publisher_name', 'publisher_logo']))->toBe([])
        ->and((int) DB::table('redirects')->sum('hits'))->toBe(0)
        ->and(is_dir($site.'/'.AddressCounts::FOLDER))->toBeFalse()
        ->and(is_dir($site.'/benchmark-seo'))->toBeFalse()
        ->and(rtrim((string) config('page_cache.stores.file.path'), '/\\'))->toBe($site)
        ->and(collect($runner->notes())->contains(fn (string $note): bool => str_starts_with($note, 'Sitemaps: ')))->toBeTrue();

    File::deleteDirectory($site);
});

it('seeds 13 months of AI requests with their monthly totals, and measures the record\'s pages, a month, a person and a task without changing it', function (): void {
    app(BenchmarkSeeder::class)->seed(500, aiActivity: 2000);

    expect(DB::table('ai_activity')->count())->toBe(2000)
        ->and(DB::table('ai_activity')->distinct()->orderBy('task')->pluck('task')->all())->toBe(['alt-text', 'metadata', 'rewrite'])
        ->and(DB::table('ai_activity')->where('task', 'rewrite')->whereNull('variant')->exists())->toBeFalse()
        ->and(DB::table('ai_activity')->where('created_at', '<', now()->subMonths(12))->exists())->toBeTrue()
        ->and((int) DB::table('ai_monthly_spend')->sum('requests'))->toBe(2000)
        ->and((int) DB::table('ai_monthly_spend')->sum('cost_microunits'))->toBe((int) DB::table('ai_activity')->sum('cost_microunits'));

    $spent = (int) DB::table('ai_monthly_spend')->sum('cost_microunits');
    $runner = app(BenchmarkRunner::class);

    $results = collect($runner->run(1))->keyBy('name');

    expect($results->keys()->all())->toContain(
        'ai activity: page one', 'ai activity: halfway down',
        'ai activity: one month, page one', 'ai activity: one month, halfway in',
        'ai activity: one person, one month', 'ai activity: one task, one month',
        'ai activity: the month\'s spend and the filters',
        'terms: the tabs\' counts', 'media: the months',
    );

    // Reading the record writes nothing, and leaves it and its totals as seeded.
    foreach ($results->filter(fn (ScenarioResult $result): bool => str_starts_with($result->name, 'ai activity: ')) as $result) {
        expect($result->writes)->toBe(0);
    }

    expect(DB::table('ai_activity')->count())->toBe(2000)
        ->and((int) DB::table('ai_monthly_spend')->sum('cost_microunits'))->toBe($spent)
        ->and(collect($runner->notes())->contains(fn (string $note): bool => str_starts_with($note, 'AI activity: 2,000 requests')))->toBeTrue();
});

it('gives every tenth file an item it was uploaded for, and measures the library narrowed to one item’s files (I-553)', function (): void {
    app(BenchmarkSeeder::class)->seed(500);

    $results = collect(app(BenchmarkRunner::class)->run(2))->keyBy('name');

    expect(DB::table('media')->whereNotNull('uploaded_for')->count())->toBe(50)
        ->and(DB::table('media')->where('id', 'M'.str_pad('20', 25, '0', STR_PAD_LEFT))->value('uploaded_for'))->toBe(BenchmarkSeeder::itemId(20))
        ->and($results->has('media: uploaded for one item'))->toBeTrue()
        ->and($results['media: uploaded for one item']->writes)->toBe(0);
});
