<?php

declare(strict_types=1);

namespace App\Domain\Benchmark\ImportBench;

use App\Domain\Import\ImportMessageLevel;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Operations\ConnectImportSource;
use App\Domain\Import\Operations\ContinueImportRun;
use App\Domain\Import\Operations\Input\ConnectImportSourceInput;
use App\Domain\Import\Operations\Input\ContinueImportRunInput;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\Input\StopImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Import\Operations\StopImportRun;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Themes\ActiveTheme;
use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Imports the benchmark's synthetic site through the real engine and the
 * real database queue, worked in this process one job at a time, stops the
 * run partway, carries it on, and times every step (D4 to D6). It measures
 * what Teme does, never the old site's own speed or its downloads, which
 * the live copy's run does.
 */
final class ImportBenchmark
{
    /** Whether a statement is counted now: only while a job runs. */
    private bool $counting = false;

    /** The statements the job running now has sent. */
    private int $jobStatements = 0;

    public function __construct(private readonly ImportPhases $phases) {}

    /**
     * Imports the site, stopping the run once $stopAt posts and pages have
     * come (never when 0), carrying it on $pausedSeconds later.
     *
     * @return array{
     *     state: string, seconds: float, statements: int,
     *     stoppedAt: int|null, drain: float|null, reread: int, rereadSeconds: float|null,
     *     steps: list<array{key: string, label: string, seconds: float, objects: int, statements: int}>,
     *     contentEarly: float|null, contentLate: float|null,
     *     checkErrors: int, checkEach: float|null, addresses: int,
     *     mapRows: int, counts: array{posts: int, comments: int, people: int, terms: int, redirects: int}
     * }
     */
    public function run(BenchmarkSite $site, int $stopAt, int $pausedSeconds): array
    {
        $companion = new BenchmarkCompanion($site);
        $connection = DB::getDefaultConnection();
        $keys = ['teme.import.local_sources', 'queue.default', 'queue.batching.database', 'queue.failed.database', 'queue.connections.database.connection', 'queue.connections.database-long.driver', 'queue.connections.database-long.connection'];
        $held = array_combine($keys, array_map(fn (string $key): mixed => config($key), $keys));
        $http = Http::getFacadeRoot();

        config([
            'teme.import.local_sources' => true,
            'queue.default' => 'database',
            'queue.batching.database' => $connection,
            'queue.failed.database' => $connection,
            'queue.connections.database.connection' => $connection,
            'queue.connections.database-long.driver' => 'database',
            'queue.connections.database-long.connection' => $connection,
        ]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $companion->respond($request));

        try {
            return $this->measure($site, $companion, $stopAt, $pausedSeconds);
        } finally {
            config($held);
            Http::swap($http);
        }
    }

    /**
     * The report file for a result: *Every word* of the plan's import
     * benchmark section. $about holds `label`, `date`, `postgres`, `machine`
     * and `load`, and may hold `paused`, the seconds between the stop and the
     * carry-on.
     *
     * @param  array<string, mixed>  $result  what run() returned
     * @param  array<string, mixed>  $about
     */
    public function report(array $result, array $about): string
    {
        $counts = $result['counts'];
        $whole = fn (int|float $number): string => number_format($number);
        $lines = [
            "# Import benchmark — {$about['label']}",
            '',
            "- **Date:** {$about['date']} · **PostgreSQL:** {$about['postgres']} · **Machine:** {$about['machine']} · **Host load:** {$about['load']}",
            "- **The old site:** {$whole($counts['posts'])} posts and pages, {$whole($counts['comments'])} comments, {$whole($counts['people'])} people, {$whole($counts['terms'])} categories and tags, {$whole($counts['redirects'])} redirect rules, {$whole($result['addresses'])} addresses; no files (downloads are timed on a real site)",
            '- **The run:** '.number_format($result['seconds'], 1)." s in all, {$whole($result['statements'])} statements; ".($result['stoppedAt'] === null
                ? 'never stopped'
                : "stopped after {$whole($result['stoppedAt'])} posts and pages, carried on ".(int) ($about['paused'] ?? 0).' s later'),
            '',
            '| Step | Seconds | Objects | ms an object | Statements |',
            '|---|---|---|---|---|',
        ];
        $objects = 0;

        foreach ($result['steps'] as $step) {
            $objects += $step['objects'];
            $lines[] = "| {$step['label']} | ".number_format($step['seconds'], 1).' | '.$whole($step['objects']).' | '.self::each($step['seconds'], $step['objects']).' | '.$whole($step['statements']).' |';
        }

        $lines[] = '| **The whole run** | '.number_format($result['seconds'], 1).' | '.$whole($objects).' | '.self::each($result['seconds'], $objects).' | '.$whole($result['statements']).' |';
        $lines[] = '';
        $lines[] = '## The stop and the carry-on';
        $lines[] = '';

        if ($result['stoppedAt'] === null) {
            $lines[] = '- Not stopped: the run went on to its end.';
        } else {
            $drain = number_format((float) $result['drain'], 1);
            $seconds = number_format((float) $result['rereadSeconds'], 1);
            $lines[] = "- Stopped through `StopImportRun` after {$whole($result['stoppedAt'])} posts and pages; the queue was empty {$drain} s later.";
            $each = self::each((float) $result['rereadSeconds'], $result['stoppedAt']);
            $lines[] = "- Carried on through `ContinueImportRun`: {$result['reread']} pages read again before the first new post, in {$seconds} s ({$each} ms an item already brought).";
        }

        $lines[] = "- At the end: the run {$result['state']}; the address check left {$result['checkErrors']} errors; the map holds {$whole($result['mapRows'])} objects, each once; the site holds {$whole($counts['posts'])} posts and pages, {$whole($counts['comments'])} comments, {$whole($counts['people'])} people, {$whole($counts['terms'])} categories and tags and {$whole($counts['redirects'])} redirects, as the old site has.";
        $lines[] = '';
        $lines[] = '## What decides I-444 and I-913';
        $lines[] = '';

        $early = $result['contentEarly'];
        $late = $result['contentLate'];
        $lines[] = '- **Posts and pages:** '.($early === null || $late === null
            ? 'too few to compare.'
            : number_format($early, 2).' ms an item over the first 5,000, '.number_format($late, 2).' over the last 5,000 ('.number_format($early > 0 ? $late / $early : 0, 2).' times).');

        $each = $result['checkEach'];
        $lines[] = '- **The address check:** '.($each === null
            ? 'not run.'
            : number_format($each, 2)." ms an address over {$whole($result['addresses'])} addresses; 100,000 would take about ".number_format($each * 100000 / 60000, 1).' minutes.');

        return implode("\n", $lines)."\n";
    }

    /**
     * Connects, starts, works the queue, stops and carries on, and reads what
     * the site holds at the end.
     *
     * @return array{
     *     state: string, seconds: float, statements: int,
     *     stoppedAt: int|null, drain: float|null, reread: int, rereadSeconds: float|null,
     *     steps: list<array{key: string, label: string, seconds: float, objects: int, statements: int}>,
     *     contentEarly: float|null, contentLate: float|null,
     *     checkErrors: int, checkEach: float|null, addresses: int,
     *     mapRows: int, counts: array{posts: int, comments: int, people: int, terms: int, redirects: int}
     * }
     */
    private function measure(BenchmarkSite $site, BenchmarkCompanion $companion, int $stopAt, int $pausedSeconds): array
    {
        DB::listen(function (): void {
            if ($this->counting) {
                $this->jobStatements++;
            }
        });

        /** @var array{sourceId: string} $connected */
        $connected = $this->asOperator(fn (): mixed => app(ConnectImportSource::class)->execute(new ConnectImportSourceInput(BenchmarkSite::ADDRESS, BenchmarkCompanion::USER, 'bench-password', null)));
        /** @var array{runId: string} $started */
        $started = $this->asOperator(fn (): mixed => app(StartImportRun::class)->execute(new StartImportRunInput($connected['sourceId'], ImportRunKind::Full, ['pause_ms' => 0], $this->choices())));
        $runId = $started['runId'];

        $steps = [];
        $series = [];
        $total = 0.0;
        $statements = 0;
        $lastObjects = 0;
        /** @var array<string, true> $checked */
        $checked = [];
        $lastPosts = 0;
        $stoppedAt = null;
        $stoppedClock = null;
        $stage = 'first';
        $baseServed = 0;
        $grown = false;
        $rereadSeconds = 0.0;
        $reread = 0;

        $after = function (string $step, float $seconds) use (&$steps, &$series, &$total, &$statements, &$lastObjects, &$checked, &$lastPosts, &$stoppedAt, &$stoppedClock, &$stage, &$baseServed, &$grown, &$rereadSeconds, &$reread, $runId, $stopAt, $companion): void {
            $jobStatements = $this->jobStatements;
            $run = DB::table('import_runs')->where('id', $runId)->first(['created_count', 'updated_count', 'skipped_count', 'failed_count']);
            $objects = (int) $run->created_count + (int) $run->updated_count + (int) $run->skipped_count + (int) $run->failed_count;
            $served = $companion->takeAddresses();
            $steps[$step] ??= ['seconds' => 0.0, 'objects' => 0, 'statements' => 0];
            $steps[$step]['seconds'] += $seconds;
            $steps[$step]['statements'] += $jobStatements;
            $steps[$step]['objects'] += max(0, $objects - $lastObjects);
            $total += $seconds;
            $statements += $jobStatements;
            $lastObjects = $objects;

            // Distinct addresses, not items served: a job that runs out of time part-way through a
            // page leaves the rest to the next, which reads that page again from its cursor (issue 316).
            if ($step === 'check') {
                $checked += array_fill_keys($served, true);
            }

            if ($step !== 'content' || $stage === 'drain') {
                return;
            }

            $posts = (int) DB::table('import_map')->where('object_type', 'post')->count();

            if ($stage === 'second' && ! $grown) {
                if ($posts <= $stoppedAt) {
                    $rereadSeconds += $seconds;

                    return;
                }

                $grown = true;
                $reread = $companion->served('content') - $baseServed - 1;
            }

            if ($posts > $lastPosts) {
                $series[] = ['count' => $posts, 'seconds' => $seconds, 'items' => $posts - $lastPosts];
            }

            $lastPosts = $posts;

            if ($stage === 'first' && $stopAt > 0 && $posts >= $stopAt) {
                $this->asOperator(fn (): mixed => app(StopImportRun::class)->execute(new StopImportRunInput($runId, null)));
                $stoppedAt = $posts;
                $stoppedClock = hrtime(true);
                $stage = 'drain';
            }
        };

        $this->work($runId, $after);

        $drain = $stoppedClock === null ? null : (hrtime(true) - $stoppedClock) / 1e9;

        if ($stoppedAt !== null && $this->state($runId) === ImportRunState::Stopped->value) {
            Sleep::for($pausedSeconds)->seconds();
            $this->asOperator(fn (): mixed => app(ContinueImportRun::class)->execute(new ContinueImportRunInput($runId, null)));
            $stage = 'second';
            $baseServed = $companion->served('content');
            $this->work($runId, $after);
        }

        $addresses = count($checked);
        $order = array_flip(array_map(fn ($phase): string => $phase->key(), $this->phases->all()));
        uksort($steps, fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
        $timed = [];

        foreach ($steps as $key => $tally) {
            $timed[] = [
                'key' => $key,
                'label' => $this->phases->find($key)?->label() ?? $key,
                'seconds' => $tally['seconds'],
                'objects' => $key === 'check' ? $addresses : $tally['objects'],
                'statements' => $tally['statements'],
            ];
        }

        $checkSeconds = $steps['check']['seconds'] ?? null;
        [$early, $late] = $this->windows($series);

        return [
            'state' => $this->state($runId),
            'seconds' => $total,
            'statements' => $statements,
            'stoppedAt' => $stoppedAt,
            'drain' => $drain,
            'reread' => $reread,
            'rereadSeconds' => $stoppedAt === null ? null : $rereadSeconds,
            'steps' => $timed,
            'contentEarly' => $early,
            'contentLate' => $late,
            'checkErrors' => DB::table('import_messages')->where('run_id', $runId)->where('level', ImportMessageLevel::Error->value)->where('code', 'like', 'check.%')->count(),
            'checkEach' => $checkSeconds === null || $addresses === 0 ? null : $checkSeconds * 1000 / $addresses,
            'addresses' => $addresses,
            'mapRows' => DB::table('import_map')->count(),
            'counts' => [
                'posts' => DB::table('contents')->count(),
                'comments' => DB::table('comments')->count(),
                'people' => DB::table('users')->count(),
                'terms' => DB::table('terms')->count(),
                'redirects' => DB::table('redirects')->count(),
            ],
        ];
    }

    /**
     * Works the database queue's two queues in this process, the long one first, one job at a time, until the
     * run has ended and no job is left. $after hears each job's step and seconds.
     *
     * @param  Closure(string, float): void  $after
     */
    private function work(string $runId, Closure $after): void
    {
        $worker = app('queue.worker');
        $options = new WorkerOptions(name: 'bench', backoff: 0, memory: 2048, timeout: 0, sleep: 0, maxTries: 3, force: true);

        while (true) {
            $next = DB::table('jobs')->min('available_at');

            if ($next === null) {
                if (DB::table('import_runs')->where('id', $runId)->value('state') !== ImportRunState::Running->value) {
                    return;
                }

                throw new RuntimeException('The import is running, but no job is queued.');
            }

            $wait = (int) $next - now()->getTimestamp();

            if ($wait > 0) {
                // A job held back after a failure: wait for it as a worker would.
                Sleep::for($wait)->seconds();

                continue;
            }

            $step = (string) DB::table('import_runs')->where('id', $runId)->value('phase');
            // Each job in a fresh scope, as a worker's next job starts.
            app()->forgetScopedInstances();
            $this->jobStatements = 0;
            $started = hrtime(true);
            $this->counting = true;

            try {
                $worker->runNextJob('database', 'long,default', $options);
            } finally {
                $this->counting = false;
            }

            $seconds = (hrtime(true) - $started) / 1e9;
            $this->forgetRequests();
            $after($step, $seconds);
        }
    }

    /**
     * Forgets the requests the fake kept, so a run of thousands of pages
     * doesn't keep every answer in memory; the companion counts what it served.
     */
    private function forgetRequests(): void
    {
        $factory = Http::getFacadeRoot();
        (fn () => $this->recorded = [])->call($factory);
    }

    /**
     * The milliseconds an item over the first and the last 5,000 posts and
     * pages (the first and last half when there are fewer than 10,000).
     *
     * @param  list<array{count: int, seconds: float, items: int}>  $series
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function windows(array $series): array
    {
        $total = $series === [] ? 0 : $series[count($series) - 1]['count'];
        $window = $total >= 10000 ? 5000 : intdiv($total, 2);

        if ($window < 1) {
            return [null, null];
        }

        $each = function (array $jobs) use ($window): float {
            $seconds = 0.0;
            $items = 0;

            foreach ($jobs as $job) {
                $seconds += $job['seconds'];
                $items += $job['items'];

                if ($items >= $window) {
                    break;
                }
            }

            return $seconds * 1000 / max(1, $items);
        };

        return [$each($series), $each(array_reverse($series))];
    }

    /**
     * Milliseconds an object, to two places; blank where there is no object.
     */
    private static function each(float $seconds, int $objects): string
    {
        return $objects === 0 ? '' : number_format($seconds * 1000 / $objects, 2);
    }

    private function state(string $runId): string
    {
        return (string) DB::table('import_runs')->where('id', $runId)->value('state');
    }

    /**
     * What the operator chooses at the start: the theme's first place for the primary menu, and no outside images.
     *
     * @return array<string, mixed>
     */
    private function choices(): array
    {
        $places = array_keys(app(ActiveTheme::class)->get()?->manifest?->navigations() ?? []);

        return ['menu_locations' => ['primary' => $places[0] ?? ''], 'outside_images' => []];
    }

    /**
     * Calls with the operator's elevation bound and puts the previous one
     * back: the jobs run without it, as a real worker's jobs do (B-098, I-392).
     */
    private function asOperator(Closure $call): mixed
    {
        $held = app(Elevation::class);
        app()->instance(Elevation::class, new OperatorElevation);

        try {
            return $call();
        } finally {
            app()->instance(Elevation::class, $held);
        }
    }
}
