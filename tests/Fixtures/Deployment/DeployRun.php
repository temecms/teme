<?php

declare(strict_types=1);

namespace Tests\Fixtures\Deployment;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * teme:deploy as a test runs it: in this process, its output read from a
 * buffer of its own, since Artisan::output() reads the last command the
 * deploy itself called; or as another process on a database the test
 * names. Also the fixture migrations' folders and names.
 */
final readonly class DeployRun
{
    /** This part's own migration, which a test can make wait again. */
    public const string REBUILDS = '2027_01_26_100000_create_release_rebuilds_table';

    public const string PROBE = '2099_01_01_000000_add_deploy_probe';

    public const string FLAGGED = '2099_01_01_000100_add_deploy_flagged';

    public const string ASKS = '2099_01_01_000200_ask_for_search_rebuild';

    public const string FAILS = '2099_01_01_000300_fail_on_purpose';

    public const string DROPS = '2099_01_01_000400_drop_release_rebuilds';

    public const string BROKEN = '2099_01_01_000500_cannot_load';

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function __construct(
        public int $status,
        public string $output,
        public ?array $json,
        public string $errors,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public static function here(array $options = []): self
    {
        $buffer = new BufferedOutput;
        $status = Artisan::call('teme:deploy', $options, $buffer);

        return self::of($status, $buffer->fetch(), '');
    }

    /**
     * Starts teme:deploy --json as another process on $database, with the
     * test's own environment and $environment over it, and doesn't wait.
     *
     * @param  array<string, string>  $environment
     */
    public static function start(string $database, array $environment = []): InvokedProcess
    {
        return Process::env([
            'DB_DATABASE' => $database,
            // The page cache this test isolated, never the developer's.
            'PAGE_CACHE_PATH' => (string) config('page_cache.stores.file.path'),
            'PAGE_CACHE_STATIC_PATH' => (string) config('page_cache.stores.static.path'),
            ...$environment,
        ])
            ->timeout(180)
            ->start([PHP_BINARY, base_path('artisan'), 'teme:deploy', '--json']);
    }

    /**
     * Points the page cache's folders at one of this process's own, so a
     * deploy's clear and warm never touch the developer's storage/page-cache.
     */
    public static function isolatePageCache(): void
    {
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-deploy-page-cache-'.(ParallelTesting::token() ?: 'p'.getmypid());

        config([
            'page_cache.stores.file.path' => $folder,
            'page_cache.stores.static.path' => $folder.DIRECTORY_SEPARATOR.'static',
        ]);
    }

    /**
     * Waits for a started deploy, and reads what it printed.
     */
    public static function finished(InvokedProcess $process): self
    {
        $result = $process->wait();

        return self::of((int) $result->exitCode(), $result->output(), $result->errorOutput());
    }

    /**
     * A fixture migrations folder, for app('migrator')->path().
     */
    public static function migrations(string $folder): string
    {
        return base_path("tests/Fixtures/Deployment/migrations/{$folder}");
    }

    /**
     * Those of $lines found in the output, in the order they first appear,
     * so a test compares the result with $lines.
     *
     * @param  list<string>  $lines
     *
     * @return list<string>
     */
    public function inOrder(array $lines): array
    {
        $found = [];

        foreach ($lines as $line) {
            $at = strpos($this->output, $line);

            if ($at !== false) {
                $found[$at] = $line;
            }
        }

        ksort($found);

        return array_values($found);
    }

    private static function of(int $status, string $output, string $errors): self
    {
        $json = json_decode(trim($output), true);

        return new self($status, $output, is_array($json) ? $json : null, $errors);
    }
}
