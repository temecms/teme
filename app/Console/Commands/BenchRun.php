<?php

/**
 * `teme:bench:run`: replays every list, filter, search, term screen and quick
 * edit against the seeded benchmark database, checks each against its budget
 * and query-plan rules, and writes the result to docs/benchmarks/. It only
 * ever touches a database whose name ends in _bench.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Benchmark\BenchmarkDatabase;
use App\Domain\Benchmark\BenchmarkRunner;
use App\Domain\Benchmark\ScenarioResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BenchRun extends Command
{
    /** @var string */
    protected $signature = 'teme:bench:run {--runs=20 : Timed runs per scenario} {--label= : Names the result file} {--host-load= : How busy the machine was, for the report (I-154)}';

    /** @var string */
    protected $description = 'Run the scale benchmark and record the result (a _bench database only)';

    public function handle(BenchmarkRunner $runner): int
    {
        try {
            BenchmarkDatabase::select();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $runs = max(1, (int) $this->option('runs'));
        $results = $runner->run($runs);

        $this->table(
            ['Scenario', 'p95 ms', 'Queries', 'Writes', 'Valkey', 'Budget ms', 'Result'],
            array_map(fn (ScenarioResult $result): array => [
                $result->name,
                number_format($result->p95Ms, 1),
                $result->queries,
                $result->writes,
                $result->commands,
                $result->budgetMs,
                $result->passed() ? 'PASS' : 'FAIL '.implode('; ', $result->problems),
            ], $results),
        );

        foreach ($runner->notes() as $note) {
            $this->line($note);
        }

        $label = (string) ($this->option('label') ?: 'local-'.date('Y-m-d'));
        $path = base_path("docs/benchmarks/{$label}.md");

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        // "\n", not PHP_EOL: the same bytes on every platform (B-037).
        file_put_contents($path, implode("\n", $this->report($label, $runs, $results, $runner->notes()))."\n");
        $this->info("Wrote {$path}");

        return collect($results)->every(fn (ScenarioResult $result): bool => $result->passed()) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<ScenarioResult>  $results
     * @param  list<string>  $notes
     *
     * @return list<string>
     */
    private function report(string $label, int $runs, array $results, array $notes): array
    {
        $lines = [
            "# Scale benchmark — {$label}",
            '',
            '- Date: '.date('Y-m-d H:i:s'),
            '- PostgreSQL: '.DB::scalar('select version()'),
            '- Items: '.number_format((int) DB::table('contents')->count()),
            '- Media items: '.number_format((int) DB::table('media')->count()),
            '- Machine: '.php_uname(),
            '- Host load: '.($this->option('host-load') ?: 'not recorded'),
            '- Cache store: '.(string) config('cache.default').'; each scenario named `cache:` runs with the page cache on, in a folder and a prefix of its own, and every other with it off',
            "- Timed runs per scenario: {$runs}; each time is the 95th percentile, measured on the server. Queries are SELECTs; Writes, every other statement sent, a failed one too; Valkey, the commands sent to the page store's",
            '',
            '| Scenario | Group | p95 (ms) | Queries | Writes | Valkey | Budget (ms) | Result |',
            '|---|---|---|---|---|---|---|---|',
        ];

        foreach ($results as $result) {
            $lines[] = sprintf(
                '| %s | %s | %s | %d | %d | %d | %d | %s |',
                $result->name,
                $result->group,
                number_format($result->p95Ms, 1),
                $result->queries,
                $result->writes,
                $result->commands,
                $result->budgetMs,
                $result->passed() ? 'PASS' : 'FAIL',
            );
        }

        foreach ($results as $result) {
            foreach ($result->problems as $problem) {
                $lines[] = '';
                $lines[] = "**{$result->name}:** {$problem}";
            }
        }

        $read = array_filter($results, fn (ScenarioResult $result): bool => $result->indexes !== []);

        if ($read !== []) {
            $lines[] = '';
            $lines[] = '## Indexes read';
            $lines[] = '';

            foreach ($read as $result) {
                $lines[] = "- {$result->name}: ".implode(', ', $result->indexes);
            }
        }

        if ($notes !== []) {
            $lines[] = '';
            $lines[] = '## Notes';
            $lines[] = '';

            foreach ($notes as $note) {
                $lines[] = "- {$note}";
            }
        }

        return $lines;
    }
}
