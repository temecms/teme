<?php

/**
 * `teme:bench:import`: imports a synthetic WordPress site into its own
 * benchmark database, stops the import halfway, carries it on, times every
 * step, and writes the result to docs/benchmarks/. It only ever touches a
 * database whose name ends in _bench, and it starts by wiping that database.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Benchmark\BenchmarkDatabase;
use App\Domain\Benchmark\ImportBench\BenchmarkSite;
use App\Domain\Benchmark\ImportBench\ImportBenchmark;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BenchImport extends Command
{
    /** @var string */
    protected $signature = 'teme:bench:import
        {--items=50000 : Posts and pages on the synthetic site}
        {--comments=100000 : Comments on its posts}
        {--stop-at=25000 : Stop the run once this many posts and pages have come, then carry it on (0: never)}
        {--paused=5 : Seconds between the stop and the carry-on}
        {--database=teme_import_bench : The database to import into; its name must end in _bench}
        {--label= : Names the report file}
        {--host-load= : How busy the machine was, for the report (I-154)}';

    /** @var string */
    protected $description = 'Import a synthetic WordPress site into a benchmark database, stop it halfway, carry it on, and time every step';

    public function handle(ImportBenchmark $benchmark): int
    {
        $default = DB::getDefaultConnection();
        config(['database.connections.bench.database' => (string) $this->option('database')]);

        try {
            $database = BenchmarkDatabase::select();
        } catch (RuntimeException $exception) {
            // select() moves the default connection before it refuses: put it back.
            DB::setDefaultConnection($default);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // At once, so no batch or failed job can reach the developer's database, even before the run sets them.
        config(['queue.batching.database' => 'bench', 'queue.failed.database' => 'bench', 'queue.connections.database.connection' => 'bench']);

        $site = new BenchmarkSite(items: (int) $this->option('items'), comments: (int) $this->option('comments'));
        $paused = max(0, (int) $this->option('paused'));
        $this->info("Rebuilding [{$database}] and importing a site of {$site->items} posts and pages and {$site->comments} comments.");

        $this->call('migrate:fresh', ['--database' => 'bench', '--force' => true]);
        (new RoleSeeder)->run();

        $result = $benchmark->run($site, (int) $this->option('stop-at'), $paused);

        $label = (string) ($this->option('label') ?: 'local-'.date('Y-m-d'));
        $path = base_path("docs/benchmarks/{$label}-import.md");

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        // "\n", not PHP_EOL: the same bytes on every platform (B-037).
        file_put_contents($path, $benchmark->report($result, [
            'label' => $label,
            'date' => date('Y-m-d H:i:s'),
            'postgres' => (string) DB::scalar('select version()'),
            'machine' => php_uname(),
            'load' => (string) ($this->option('host-load') ?: 'not recorded'),
            'paused' => $paused,
        ]));
        $this->info("Wrote {$path}");
        $this->info(sprintf('The run took %.1f seconds and ended %s.', $result['seconds'], $result['state']));

        return $result['state'] === 'finished' && $result['checkErrors'] === 0 && $result['counts'] === $site->expected() ? self::SUCCESS : self::FAILURE;
    }
}
