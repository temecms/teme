<?php

declare(strict_types=1);

namespace App\Domain\Benchmark;

use App\Domain\Addresses\Addresses;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Comments\Emptying;
use App\Domain\Comments\EmptyingResult;
use App\Domain\Comments\Jobs\EmptyComments;
use App\Domain\Comments\Queries\CommentList;
use App\Domain\Comments\Queries\CommentListRequest;
use App\Domain\Comments\Queries\CommentsWaiting;
use App\Domain\Comments\Queries\EmptyingRequest;
use App\Domain\Comments\Queries\TrashedComments;
use App\Domain\Site\CommentViews;
use App\Domain\Support\Queries\Cursor;
use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The comment scenarios at the scale docs/studies/comments.md §3.11 sets:
 * the long thread's pages and a link to its last comment; the Comments
 * screen's Pending view, counts and badge; what Empty spam covers and one
 * permanent deletion inside it; the trash purge's chunk; and the daily
 * erasure of commenters' addresses. Each puts back what it changes, and
 * none runs on a data set seeded without comments.
 *
 * @phpstan-type Scenario array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, queriesLike?: string, items?: int, within?: list<array{items: int, seconds: int, what: string}>, planWrites?: bool, prepare?: Closure(): void, finish?: Closure(): void, before?: Closure(int): void}
 */
final class CommentScenarios
{
    /** Comments one Empty spam run deletes, each timed. */
    private const int EMPTIED = 25;

    /** The first number of the spam put on EMPTYING_ITEM, above any seeded comment's. */
    private const int SPAM_FIRST = 900_000_000;

    /** Whose that spam is, so a run's leftovers are found. */
    private const string SPAMMER = 'spam@bench.test';

    /** @var list<string> What the last all() noted beside the table. */
    private array $notes = [];

    public function __construct(
        private readonly CommentList $list,
        private readonly CommentsWaiting $waiting,
        private readonly TrashedComments $trashed,
        private readonly Emptying $emptying,
        private readonly SettingsReader $settings,
    ) {}

    /**
     * Every comment scenario; none, with a note saying why, when the data
     * set has no comments. $reader draws a public page as a reader's request.
     *
     * @param  Closure(string): (Closure(int): int)  $reader
     *
     * @return list<Scenario>
     */
    public function all(int $runs, string $admin, Closure $reader): array
    {
        $this->notes = [];

        if (! DB::table('comments')->exists()) {
            $this->notes[] = 'Comments: not measured, since the data set has none (teme:bench:seed --comments).';

            return [];
        }

        return [...$this->thread($reader), ...$this->screen((int) $admin), ...$this->work($runs, $admin)];
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
     * The long thread: its article; its page 2; page 80, or the last on a
     * smaller set, held to page 2's statements, since page 1 is the article
     * itself; and a link to its newest comment, the deepest reply of the last
     * chain, which shown() checks against the nine above it.
     *
     * @param  Closure(string): (Closure(int): int)  $reader
     *
     * @return list<Scenario>
     */
    private function thread(Closure $reader): array
    {
        $item = BenchmarkSeeder::itemId(BenchmarkSeeder::THREAD_ITEM);
        $path = (string) DB::table('paths')->where('target_type', 'content')->where('target_id', $item)->where('is_current', true)->value('path');
        $url = Addresses::toUrl($path, 'post');
        $threads = (int) DB::table('comment_stats')->where('content_id', $item)->value('thread_count');
        $pages = max(1, (int) ceil($threads / DiscussionSettings::current()->perPage));
        $deep = min(80, $pages);
        $last = (string) DB::table('comments')->where('content_id', $item)->orderByDesc('id')->value('id');

        $scenarios = [
            ['name' => 'public: article, long thread', 'group' => 'public-item', 'indexOrder' => false, 'run' => $reader($url)],
            ['name' => 'public: link to its last comment', 'group' => 'public-item', 'indexOrder' => false, 'run' => $this->redirected('/_teme/comment/'.$last, '#comment-'.$last)],
        ];

        if ($pages >= 2) {
            $scenarios[] = ['name' => 'public: comments, page 2', 'group' => 'public-list', 'indexOrder' => true, 'run' => $reader(CommentViews::pageUrl($url, 2))];
        }

        if ($deep > 2) {
            $scenarios[] = ['name' => "public: comments, page {$deep}", 'group' => 'public-deep', 'indexOrder' => true, 'queriesLike' => 'public: comments, page 2', 'run' => $reader(CommentViews::pageUrl($url, $deep))];
        }

        return $scenarios;
    }

    /**
     * The Comments screen as an administrator: the Pending view's first page
     * and one halfway down, held to its statements; the views' counts; the
     * menu badge for an editor and an author; what Empty spam covers; and one
     * chunk of the trash purge.
     *
     * @return list<Scenario>
     */
    private function screen(int $admin): array
    {
        $editor = User::query()->where('email', 'editor@bench.test')->firstOrFail();
        // The author of THREAD_ITEM, and of every other commented post n % 200 = 17.
        $author = User::query()->where('email', 'author18@bench.test')->firstOrFail();
        $pending = DB::table('comments')->where('status', CommentStatus::PENDING)->count();
        // OFFSET only locates the starting row, and is never timed.
        $middle = (string) DB::table('comments')->where('status', CommentStatus::PENDING)->orderByDesc('id')->offset(intdiv($pending, 2))->limit(1)->value('id');
        $page = fn (?string $after): Closure => fn (int $i): array => $this->list->page(new CommentListRequest(viewerId: $admin, view: CommentStatus::PENDING, after: $after));
        $trashDays = $this->settings->int('content', 'trash_days');

        return [
            ['name' => 'comments: pending, page one', 'group' => 'list', 'indexOrder' => true, 'run' => $page(null)],
            ['name' => 'comments: pending, deep page', 'group' => 'list', 'indexOrder' => true, 'queriesLike' => 'comments: pending, page one', 'run' => $page(Cursor::encode([$middle]))],
            ['name' => 'comments: view counts', 'group' => 'list', 'indexOrder' => false, 'run' => fn (int $i): array => $this->list->counts($admin)],
            ['name' => 'comments: menu badge, an editor', 'group' => 'list', 'indexOrder' => false, 'run' => fn (int $i): int => $this->waiting->countFor($editor)],
            ['name' => 'comments: menu badge, an author', 'group' => 'list', 'indexOrder' => false, 'run' => fn (int $i): int => $this->waiting->countFor($author)],
            ['name' => 'comments: what Empty spam covers', 'group' => 'filtered', 'indexOrder' => false, 'run' => fn (int $i): array => $this->list->emptying(new EmptyingRequest($admin, CommentStatus::SPAM, null, null, CarbonImmutable::now()))],
            ['name' => 'comments: trash purge, one chunk of 1,000', 'group' => 'scheduler', 'indexOrder' => false, 'run' => fn (int $i): array => $this->trashed->olderThan(now()->subDays($trashDays), 1000)],
        ];
    }

    /**
     * One permanent deletion inside Empty spam, timed per comment against
     * how many an Empty press deletes in its request and a queued part in
     * its time; then the daily erasure of addresses, a chunk's worth put on
     * older comments before each run, as after keep_ip_days is lowered.
     *
     * @return list<Scenario>
     */
    private function work(int $runs, string $admin): array
    {
        $item = BenchmarkSeeder::itemId(BenchmarkSeeder::EMPTYING_ITEM);
        $keep = DiscussionSettings::current()->keepIpDays;
        $elevation = null;

        return [
            [
                'name' => 'comments: delete permanently, per comment (Empty spam, 25 a run)',
                'group' => 'quickedit',
                'indexOrder' => false,
                'items' => self::EMPTIED,
                'within' => [
                    // CommentEmptyController's IN_REQUEST: the HTTP layer's, which the domain doesn't read.
                    ['items' => 1000, 'seconds' => 10, 'what' => 'what an Empty press deletes in its own request'],
                    ['items' => EmptyComments::PER_JOB, 'seconds' => 30, 'what' => 'what one queued part deletes, in half the worker\'s 60 s'],
                ],
                'prepare' => function () use ($item, $runs): void {
                    $this->spam($item, self::EMPTIED * ($runs + 2));
                },
                'run' => fn (int $i): EmptyingResult => $this->emptying->run(new EmptyingRequest((int) $admin, CommentStatus::SPAM, $item, null, CarbonImmutable::now()), self::EMPTIED),
                'finish' => function () use ($item): void {
                    DB::table('comments')->where('content_id', $item)->where('author_email', self::SPAMMER)->delete();
                },
            ],
            [
                'name' => 'comments: erase addresses, the daily run',
                'group' => 'scheduler',
                'indexOrder' => false,
                'planWrites' => true,
                'prepare' => function () use (&$elevation): void {
                    // The command makes itself the operator; what was bound comes back after.
                    $elevation = app(Elevation::class);
                },
                'before' => function (int $i) use ($keep): void {
                    // A different thousand older comments each run, given an address
                    // again: the run erases them, then finds no more.
                    DB::update(sprintf(<<<'SQL'
                        UPDATE comments SET ip = %s, user_agent = ?
                        WHERE id IN (
                            SELECT id FROM comments
                            WHERE created_at < now() - make_interval(days => %d) AND ip IS NULL AND legacy_id IS NOT NULL
                            ORDER BY id OFFSET %d LIMIT 1000
                        )
                        SQL, BenchmarkSeeder::commenterAddress('legacy_id'), $keep + 1, $i * 1000), [BenchmarkSeeder::COMMENTER_BROWSER]);
                },
                'run' => fn (int $i): int => Artisan::call('teme:comments:erase-addresses'),
                'finish' => function () use (&$elevation, $keep): void {
                    // Whatever a failed run left, erased as the command erases, so
                    // only comments within keep_ip_days keep an address, as seeded.
                    DB::update(sprintf('UPDATE comments SET ip = NULL, user_agent = NULL WHERE created_at <= now() - make_interval(days => %d) AND (ip IS NOT NULL OR user_agent IS NOT NULL)', $keep));

                    if ($elevation !== null) {
                        app()->instance(Elevation::class, $elevation);
                    }
                },
            ],
        ];
    }

    /**
     * $count spam comments on the item, arrived an hour ago, numbered from
     * SPAM_FIRST: tooling, written as the seeder writes.
     */
    private function spam(string $item, int $count): void
    {
        DB::statement(sprintf(<<<'SQL'
            INSERT INTO comments (id, content_id, path, depth, kind, status, author_name, author_email, body, ip, user_agent, created_at, updated_at, status_changed_at)
            SELECT lpad((%1$d + g)::text, 26, '0'), ?, lpad((%1$d + g)::text, 26, '0') || '/', 1, 'comment', 'spam', 'Cheap Watches', ?,
                jsonb_build_object('type', 'doc', 'content', jsonb_build_array(jsonb_build_object('type', 'paragraph', 'content', jsonb_build_array(
                    jsonb_build_object('type', 'text', 'text', 'Cheap watches, number ' || g || '.'))))),
                '203.0.113.9'::inet, ?, now() - interval '1 hour', now() - interval '1 hour', now() - interval '1 hour'
            FROM generate_series(1, %2$d) g
            SQL, self::SPAM_FIRST, $count), [$item, self::SPAMMER, BenchmarkSeeder::COMMENTER_BROWSER]);
    }

    /**
     * A reader's request whose answer must redirect to $fragment, as a
     * comment's own address answers; anything else ends the run.
     *
     * @return Closure(int): int
     */
    private function redirected(string $path, string $fragment): Closure
    {
        return function (int $i) use ($path, $fragment): int {
            app()->forgetScopedInstances();

            $request = Request::create($path, 'GET', server: ['REMOTE_ADDR' => '198.51.100.'.($i % 250 + 1)]);
            $kernel = app(HttpKernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            if ($response->getStatusCode() !== 302 || ! str_ends_with((string) $response->headers->get('Location'), $fragment)) {
                throw new RuntimeException("The link {$path} answered {$response->getStatusCode()}, not a redirect to {$fragment}.");
            }

            return 302;
        };
    }
}
