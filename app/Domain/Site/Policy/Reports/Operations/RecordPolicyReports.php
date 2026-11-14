<?php

declare(strict_types=1);

namespace App\Domain\Site\Policy\Reports\Operations;

use App\Domain\Site\Policy\Reports\Operations\Input\RecordPolicyReportsInput;
use App\Domain\Site\Policy\Reports\PolicyReport;
use App\Domain\Site\Policy\Reports\PolicyReportSpool;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;
use Throwable;

/**
 * Adds the policy reports browsers sent since the last run to their table, as
 * the operator, every minute (I-3375): the spool closed a run ago becomes one
 * row for each directive, blocked origin and page, which then keeps only
 * rows seen in the last days and at most the most recently seen up to its
 * cap; then the open spool is closed for the next run. A browser's request
 * never writes the table.
 */
final class RecordPolicyReports extends BaseOperation
{
    /** Rows one statement writes. */
    private const int BATCH = 500;

    public function __construct(private readonly PolicyReportSpool $spool) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var RecordPolicyReportsInput $input */
        // The scheduler's run alone (docs/architecture/operations.md, The operator).
        if ($input->actorId() === null && Operator::isPresent()) {
            return;
        }

        throw new AuthorizationException('Only the operator writes the policy reports.');
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordPolicyReportsInput $input */
        Validator::make(
            ['keep' => $input->keep, 'days' => $input->days],
            ['keep' => ['required', 'integer', 'min:1', 'max:100000'], 'days' => ['required', 'integer', 'min:1', 'max:365']],
        )->validate();
    }

    /** @var list<string> files read and stored, to be deleted once the run has committed */
    private array $stored = [];

    /**
     * The number of lines read. A file whose lines cannot be stored is set
     * aside and reported, and the run goes on; a file read is deleted only
     * after the commit, so a rollback loses none.
     */
    protected function perform(OperationInput $input): int
    {
        /** @var RecordPolicyReportsInput $input */
        $this->stored = [];
        $folder = $this->spool->folder();
        $reading = $folder.'/'.PolicyReportSpool::READING;
        $next = $folder.'/'.PolicyReportSpool::READING_NEXT;

        // The one closed a run ago, into the first name a run isn't still holding. A rename is whole, so two runs can't both take one file.
        @rename($folder.'/'.PolicyReportSpool::CLOSED, is_file($reading) ? $next : $reading);

        $read = 0;

        foreach ([$reading, $next] as $file) {
            if (! is_file($file)) {
                continue;
            }

            try {
                // A savepoint of its own: a file that fails undoes only itself.
                $read += DB::transaction(fn (): int => $this->write($file, $input));
                $this->stored[] = $file;
            } catch (Throwable $failure) {
                report($failure);
                $this->spool->setAside($file);
            }
        }

        // Closed now, read a run from now, when no request can still be writing to it.
        if (! file_exists($folder.'/'.PolicyReportSpool::CLOSED)) {
            @rename($folder.'/'.PolicyReportSpool::OPEN, $folder.'/'.PolicyReportSpool::CLOSED);
        }

        return $read;
    }

    protected function afterCommit(mixed $result): void
    {
        foreach ($this->stored as $file) {
            @unlink($file);
        }

        $this->stored = [];
    }

    /**
     * Adds one spool's lines to the table, BATCH rows a statement: each
     * triple's count, first and last sight, last disposition and first
     * sample, keeping in memory at most twice the cap of triples, the most
     * recently seen; then lets old and surplus rows go. A line that isn't
     * whole is skipped. Returns the lines read.
     */
    private function write(string $file, RecordPolicyReportsInput $input): int
    {
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return 0;
        }

        // A request still appending to it, having opened it before it was closed, finishes first.
        flock($handle, LOCK_SH);

        $read = 0;
        /** @var array<string, array{directive: string, blocked: string, path: string, count: int, first: int, last: int, disposition: string, sample: string|null}> $seen */
        $seen = [];

        while (($line = fgets($handle)) !== false) {
            $cells = explode("\t", rtrim($line, "\n"));

            if (count($cells) !== 6 || ! ctype_digit($cells[5]) || ! in_array($cells[0], PolicyReport::DIRECTIVES, true) || ! in_array($cells[3], ['enforce', 'report'], true)) {
                continue;
            }

            [$directive, $blocked, $path, $disposition, $sample, $at] = [$cells[0], rawurldecode($cells[1]), rawurldecode($cells[2]), $cells[3], rawurldecode($cells[4]), (int) $cells[5]];
            $key = $directive."\t".$blocked."\t".$path;
            $before = $seen[$key] ?? null;
            // Moved to the end, so the array runs from least to most recently seen.
            unset($seen[$key]);
            $seen[$key] = [
                'directive' => $directive,
                'blocked' => $blocked,
                'path' => $path,
                'count' => ($before['count'] ?? 0) + 1,
                'first' => min($before['first'] ?? $at, $at),
                'last' => max($before['last'] ?? $at, $at),
                'disposition' => $disposition,
                'sample' => $before['sample'] ?? ($sample === '' ? null : $sample),
            ];
            $read++;

            if (count($seen) > 2 * $input->keep) {
                $seen = array_slice($seen, -$input->keep, null, true);
            }
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        $this->add(array_values($seen));
        $this->evict($input->keep, $input->days);

        return $read;
    }

    /**
     * @param  list<array{directive: string, blocked: string, path: string, count: int, first: int, last: int, disposition: string, sample: string|null}>  $triples
     */
    private function add(array $triples): void
    {
        foreach (array_chunk($triples, self::BATCH) as $chunk) {
            $values = [];
            $bindings = [];

            foreach ($chunk as $triple) {
                $values[] = '(?, ?, ?, ?, ?::bigint, ?::jsonb, to_timestamp(?::double precision), to_timestamp(?::double precision))';
                array_push($bindings, $triple['directive'], $triple['blocked'], $triple['path'], $triple['disposition'], $triple['count'], $triple['sample'], $triple['first'], $triple['last']);
            }

            $rows = implode(', ', $values);

            DB::insert(<<<SQL
                INSERT INTO csp_reports (directive, blocked, page, disposition, count, sample, first_seen_at, last_seen_at)
                VALUES {$rows}
                ON CONFLICT (directive, blocked, page) DO UPDATE SET
                    count = csp_reports.count + EXCLUDED.count,
                    disposition = EXCLUDED.disposition,
                    sample = COALESCE(csp_reports.sample, EXCLUDED.sample),
                    last_seen_at = GREATEST(csp_reports.last_seen_at, EXCLUDED.last_seen_at)
                SQL, $bindings);
        }
    }

    /**
     * Lets rows not seen for this many days go, then the least recently
     * seen rows past the cap.
     */
    private function evict(int $keep, int $days): void
    {
        DB::delete('DELETE FROM csp_reports WHERE last_seen_at < now() - make_interval(days => ?)', [$days]);

        $over = DB::table('csp_reports')->count() - $keep;

        if ($over > 0) {
            DB::delete('DELETE FROM csp_reports WHERE id IN (SELECT id FROM csp_reports ORDER BY last_seen_at, id LIMIT ?)', [$over]);
        }
    }
}
