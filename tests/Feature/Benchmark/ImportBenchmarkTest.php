<?php

declare(strict_types=1);

use App\Domain\Benchmark\ImportBench\BenchmarkSite;
use App\Domain\Benchmark\ImportBench\ImportBenchmark;
use App\Domain\Import\Phases\Check\CheckStep;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Fixtures\Import\CheckClock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake(syncWithCarbon: true);
});

afterEach(function (): void {
    CheckClock::leap(0);
});

/** A synthetic site small enough for the gate: three pages of posts and pages, 50 a page. */
function ibSite(): BenchmarkSite
{
    return new BenchmarkSite(items: 120, comments: 60, people: 6, categories: 4, tags: 10, rules: 5);
}

it('imports the whole synthetic site through every step, and the site then holds what the old site had', function (): void {
    $site = ibSite();

    $result = app(ImportBenchmark::class)->run($site, stopAt: 0, pausedSeconds: 0);

    expect($result['state'])->toBe('finished')
        ->and($result['stoppedAt'])->toBeNull()
        ->and($result['checkErrors'])->toBe(0)
        ->and($result['addresses'])->toBe($site->addresses())
        ->and($result['counts'])->toBe($site->expected())
        ->and(DB::table('import_map')->count())->toBe($result['mapRows']);
});

it('counts each address once when the check runs out of time part-way through a page and the next job reads that page again (#316)', function (): void {
    $site = ibSite();
    $checkJobs = 0;
    Event::listen(JobProcessing::class, function () use (&$checkJobs): void {
        $checkJobs += DB::table('import_runs')->where('phase', 'check')->exists() ? 1 : 0;
    });
    // Every reading of the check's clock comes a whole budget after the one before, so each job checks one address.
    CheckClock::leap(CheckStep::BUDGET);

    $result = app(ImportBenchmark::class)->run($site, stopAt: 0, pausedSeconds: 0);

    expect($result['state'])->toBe('finished')
        ->and($result['checkErrors'])->toBe(0)
        ->and($checkJobs)->toBeGreaterThan($site->addresses() - 2)
        ->and($result['addresses'])->toBe($site->addresses());
});

it('ends a run stopped after its first page and carried on with the old site’s rows, reading that one page again (I-332)', function (): void {
    $site = ibSite();

    $result = app(ImportBenchmark::class)->run($site, stopAt: 50, pausedSeconds: 1);

    expect($result['state'])->toBe('finished')
        ->and($result['stoppedAt'])->toBe(50)
        ->and($result['reread'])->toBe(1)
        ->and($result['checkErrors'])->toBe(0)
        ->and($result['counts'])->toBe($site->expected());
});

it('keeps its queue, its batches and its failed jobs on the database it imports into, and puts the settings back', function (): void {
    config(['queue.batching.database' => 'nowhere', 'queue.failed.database' => 'nowhere', 'queue.connections.database.connection' => 'nowhere']);

    $result = app(ImportBenchmark::class)->run(ibSite(), stopAt: 0, pausedSeconds: 0);

    expect($result['state'])->toBe('finished')
        ->and(DB::table('job_batches')->where('name', 'like', 'import:%')->count())->toBeGreaterThan(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(config('queue.batching.database'))->toBe('nowhere')
        ->and(config('queue.failed.database'))->toBe('nowhere')
        ->and(config('queue.connections.database.connection'))->toBe('nowhere');
});

it('times every step once, in run order, and writes them all into its report', function (): void {
    $result = app(ImportBenchmark::class)->run(ibSite(), stopAt: 0, pausedSeconds: 0);
    $keys = array_column($result['steps'], 'key');
    $report = app(ImportBenchmark::class)->report($result, ['label' => 'smoke', 'date' => '2026-10-18 12:00:00', 'postgres' => '18', 'machine' => 'test', 'load' => '0.1']);

    expect($keys)->toBe(array_values(array_unique($keys)))
        ->and($keys)->toContain('users', 'terms', 'content', 'second-pass', 'comments', 'redirects', 'check', 'fidelity', 'recount')
        ->and($report)->toStartWith("# Import benchmark — smoke\n")
        ->and($report)->toContain('## The stop and the carry-on', '## What decides I-444 and I-913', '| **The whole run** |');

    foreach ($result['steps'] as $step) {
        expect($report)->toContain('| '.$step['label'].' |');
    }
});

it('refuses a database whose name doesn’t end in _bench, before touching anything', function (): void {
    $this->artisan('teme:bench:import', ['--database' => 'teme'])
        ->expectsOutputToContain('_bench')
        ->assertFailed();

    // select() moves the default connection before it refuses, so ask the test's own by name.
    expect(DB::connection('pgsql')->table('import_sources')->count())->toBe(0);
});

it('expects the redirects of a site of 50,000: its 500 rules and the 29 sitemap files WordPress served', function (): void {
    expect((new BenchmarkSite)->sitemaps())->toBe(29)
        ->and((new BenchmarkSite)->expected()['redirects'])->toBe(529)
        ->and(ibSite()->sitemaps())->toBe(5);
});
