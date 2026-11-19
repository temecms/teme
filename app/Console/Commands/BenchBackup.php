<?php

/**
 * `teme:bench:backup`: builds the backup benchmark's synthetic site in its
 * own benchmark database, backs it up, checks and test-restores it, times
 * every step, and writes the result to docs/benchmarks/. It only ever touches
 * a database whose name ends in _bench, starts by wiping it, and writes the
 * site's files into this checkout's media folders, so it asks first.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\Tools\BackupTools;
use App\Domain\Backups\Verify\SpareDatabase;
use App\Domain\Benchmark\BackupBench\BackupBenchmark;
use App\Domain\Benchmark\BackupBench\BackupBenchOptions;
use App\Domain\Benchmark\BenchmarkDatabase;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class BenchBackup extends Command
{
    /** @var string */
    protected $signature = 'teme:bench:backup
        {--images=40000 : Images on the synthetic site (I-367)}
        {--items=200000 : Content items, for a database of about 3 GB}
        {--scale=0.25 : Each file’s share of its full size; 1 makes about 15 GB of originals}
        {--night=40 : New images before the second backup; ten times as many items are saved again}
        {--folder= : Where the place and the recovery key are kept; storage/app/bench-backup when none is given}
        {--database=teme_backup_bench : The database to build the site in; its name must end in _bench}
        {--verify-database=teme_bench_verify : The spare database for the test restore; its name must end in _verify}
        {--label= : Names the report file}
        {--host-load= : How busy the machine was, for the report (I-154)}
        {--force : Write into this checkout’s media folders without asking}';

    /** @var string */
    protected $description = 'Build a synthetic site of 40,000 images in a benchmark database, back it up, back it up again after a night’s change, read it back, prune it, test-restore it, and time every step';

    private const array NAMES = [
        'first' => 'The first backup',
        'night' => 'The nightly backup',
        'read-back' => 'The nightly read-back',
        'prune' => 'The weekly prune and check',
        'test-restore' => 'The weekly test restore',
    ];

    public function handle(BackupBenchmark $benchmark, BackupTools $tools): int
    {
        $scale = (float) $this->option('scale');
        $images = (int) $this->option('images');

        if ($scale <= 0 || $scale > 1 || $images < 1) {
            $this->error('Refusing to run: --scale must be above 0 and at most 1, and --images at least 1. Nothing was changed.');

            return self::FAILURE;
        }

        $folder = (string) ($this->option('folder') ?: storage_path('app/bench-backup'));

        if (! $this->option('force')) {
            $percent = rtrim(rtrim(number_format($scale * 100, 4, '.', ''), '0'), '.');
            $bytes = Number::fileSize((int) round($images * 6 * 332000 * $scale + $images * 375000 * $scale), precision: 1);
            $this->line('This makes a site of '.number_format($images)." images, every file {$percent}% of its size, about {$bytes} in all, and writes them into this checkout’s media folders:");
            $this->line('  '.(string) config('filesystems.disks.media-originals.root'));
            $this->line('  '.(string) config('filesystems.disks.media.root'));
            $this->line("It rebuilds {$this->option('database')} and keeps the backups at {$folder}.");

            if (! $this->confirm('This writes the synthetic site’s images into this checkout’s media folders and rebuilds the benchmark’s database. Carry on?', false)) {
                $this->line('Nothing was changed.');

                return self::FAILURE;
            }
        }

        $default = DB::getDefaultConnection();
        config(['database.connections.bench.database' => (string) $this->option('database')]);

        try {
            $database = BenchmarkDatabase::select();
        } catch (RuntimeException $exception) {
            DB::setDefaultConnection($default);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // At once, so no batch or failed job can reach the developer's database.
        config(['queue.batching.database' => 'bench', 'queue.failed.database' => 'bench', 'queue.connections.database.connection' => 'bench']);

        $spare = (string) $this->option('verify-database');

        if (! str_ends_with($spare, SpareDatabase::SUFFIX)) {
            DB::setDefaultConnection($default);
            $this->error("Refusing to run: the test restore only uses a database whose name ends in _verify, and this one is [{$spare}].");

            return self::FAILURE;
        }

        config(['database.connections.teme_verify.database' => $spare]);

        if (is_dir($folder.DIRECTORY_SEPARATOR.'place')) {
            DB::setDefaultConnection($default);
            $this->error("{$folder} holds a place from an earlier run. Remove it first: rm -rf {$folder}");

            return self::FAILURE;
        }

        $items = (int) $this->option('items');
        $this->info("Rebuilding [{$database}] with ".number_format($items).' items and '.number_format($images).' images.');
        $this->call('migrate:fresh', ['--database' => 'bench', '--force' => true]);
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        try {
            $result = $benchmark->run(new BackupBenchOptions(folder: $folder, items: $items, images: $images, scale: $scale, night: (int) $this->option('night')));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        } catch (BackupAlreadyRunning $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Seeded in %s s; the images’ files written in %s s.', number_format($result['seedSeconds'], 1), number_format($result['filesSeconds'], 1)));

        foreach ($result['runs'] as $run) {
            $name = self::NAMES[$run['name']];
            $seconds = number_format($run['seconds'], 1);

            if ($run['succeeded']) {
                $this->info("{$name}: done in {$seconds} s.");
            } else {
                $this->error("{$name}: failed in {$seconds} s: {$run['sentence']}");
            }

            foreach ($run['problems'] as $problem) {
                $this->line("  {$problem}");
            }
        }

        $label = (string) ($this->option('label') ?: 'local-'.date('Y-m-d'));
        $path = base_path("docs/benchmarks/{$label}-backup.md");

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $restic = collect($tools->check()->tools)->firstWhere('name', 'restic');

        // "\n", not PHP_EOL: the same bytes on every platform (B-037).
        file_put_contents($path, $benchmark->report($result, [
            'label' => $label,
            'date' => date('Y-m-d H:i:s'),
            'postgres' => (string) DB::scalar('select version()'),
            'restic' => (string) ($restic->version ?? 'not found'),
            'machine' => php_uname(),
            'load' => (string) ($this->option('host-load') ?: 'not recorded'),
        ]));
        $this->info("Wrote {$path}");

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
