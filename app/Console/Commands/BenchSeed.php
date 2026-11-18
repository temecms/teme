<?php

/**
 * `teme:bench:seed`: rebuilds the scale benchmark's database and fills it with
 * the study I data set. It only ever touches a database whose name ends in
 * _bench, and it starts by wiping that database.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Benchmark\BenchmarkDatabase;
use App\Domain\Benchmark\BenchmarkSeeder;
use Illuminate\Console\Command;
use RuntimeException;

final class BenchSeed extends Command
{
    /** @var string */
    protected $signature = 'teme:bench:seed {--items=1000000 : How many content items to create} {--media= : How many media items to create (default: as many as items)} {--comments=40000 : How many comments to create, for docs/studies/comments.md §3.11} {--redirects=100000 : How many redirects to create, for docs/studies/seo.md §3.15} {--not-found=100000 : How many addresses the 404 log holds, the most it may keep, for docs/studies/seo.md §3.15} {--ai-activity=10000000 : How many AI requests the activity record holds, over 13 months}';

    /** @var string */
    protected $description = 'Seed the scale benchmark database (a _bench database only)';

    public function handle(BenchmarkSeeder $seeder): int
    {
        try {
            $database = BenchmarkDatabase::select();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $items = (int) $this->option('items');
        $comments = (int) $this->option('comments');
        $redirects = (int) $this->option('redirects');
        $notFound = (int) $this->option('not-found');
        $aiActivity = (int) $this->option('ai-activity');
        $this->info("Rebuilding [{$database}] with {$items} items, {$comments} comments, {$redirects} redirects, {$notFound} addresses in the 404 log and {$aiActivity} AI requests.");

        $this->call('migrate:fresh', ['--database' => 'bench', '--force' => true]);

        $start = microtime(true);
        $seeder->seed($items, $this->option('media') === null ? null : (int) $this->option('media'), $comments, redirects: $redirects, notFound: $notFound, aiActivity: $aiActivity);

        $this->info(sprintf('Seeded in %.1f seconds.', microtime(true) - $start));

        return self::SUCCESS;
    }
}
