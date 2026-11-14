<?php

declare(strict_types=1);

namespace App\Domain\Redirects\Operations;

use App\Domain\Redirects\AddressCounts;
use App\Domain\Redirects\NotFoundLog;
use App\Domain\Redirects\Operations\Input\RecordAddressCountsInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

/**
 * Writes what readers met since the last run, as the operator, every minute
 * (I-297, I-298): the spool closed a run ago is added to the tables in
 * batches, each redirect's hits and each address's row in the 404 log,
 * which then keeps only the most recently seen up to its cap; then the open
 * spool is closed for the next run. A reader's request never writes either.
 */
final class RecordAddressCounts extends BaseOperation
{
    /** Rows one statement writes. */
    private const int BATCH = 500;

    public function __construct(private readonly AddressCounts $counts) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var RecordAddressCountsInput $input */
        // The scheduler's run alone (docs/architecture/operations.md, The operator).
        if ($input->actorId() === null && Operator::isPresent()) {
            return;
        }

        throw new AuthorizationException('Only the operator writes the counts.');
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordAddressCountsInput $input */
        Validator::make(
            ['keep' => $input->keep, 'ignore' => $input->ignore],
            ['keep' => ['required', 'integer', 'min:1', 'max:100000'], 'ignore' => ['present', 'array', 'max:1000'], 'ignore.*' => ['string', 'max:2000']],
        )->validate();
    }

    /**
     * @return array{hits: int, notFound: int}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var RecordAddressCountsInput $input */
        $folder = $this->counts->folder();
        $reading = $folder.'/'.AddressCounts::READING;
        $read = ['hits' => 0, 'notFound' => 0];

        // A file a failed run left behind, first, before anything is renamed over it.
        if (is_file($reading)) {
            $read = self::added($read, $this->write($reading, $input->keep, $input->ignore));
            @unlink($reading);
        }

        // Then the one closed a run ago. A rename is whole, so two runs can't both take one file.
        if (@rename($folder.'/'.AddressCounts::CLOSED, $reading)) {
            $read = self::added($read, $this->write($reading, $input->keep, $input->ignore));
            @unlink($reading);
        }

        // Closed now, read a run from now, when no request can still be writing to it.
        if (! file_exists($folder.'/'.AddressCounts::CLOSED)) {
            @rename($folder.'/'.AddressCounts::OPEN, $folder.'/'.AddressCounts::CLOSED);
        }

        return $read;
    }

    /**
     * Adds one spool's lines to the tables, BATCH rows a statement: each
     * redirect's hits and latest hit; each address's count, first and last
     * sight and latest referring host, keeping in memory at most twice the
     * cap of addresses, the most recently seen; then lets the least recently
     * seen rows past the cap go (I-298). A line that isn't whole is skipped.
     *
     * @param  list<string>  $ignore
     *
     * @return array{hits: int, notFound: int}
     */
    private function write(string $file, int $keep, array $ignore): array
    {
        $read = ['hits' => 0, 'notFound' => 0];
        $hits = [];
        $missing = [];
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return $read;
        }

        // A request still appending to it, having opened it before it was closed, finishes first.
        flock($handle, LOCK_SH);

        while (($line = fgets($handle)) !== false) {
            $cells = explode("\t", rtrim($line, "\n"));
            $at = (int) end($cells);

            if ($cells[0] === AddressCounts::HIT && count($cells) === 3 && ctype_digit($cells[2])) {
                $hits[$cells[1]] = [($hits[$cells[1]][0] ?? 0) + 1, max($hits[$cells[1]][1] ?? 0, $at)];
                $read['hits']++;

                continue;
            }

            if ($cells[0] !== AddressCounts::NOT_FOUND || count($cells) !== 4 || ! ctype_digit($cells[3])) {
                continue;
            }

            $path = rawurldecode($cells[1]);

            if (NotFoundLog::ignored($path, $ignore)) {
                continue;
            }

            $seen = $missing[$path] ?? [0, $at, $at, null];
            // Moved to the end, so the array runs from least to most recently seen.
            unset($missing[$path]);
            $missing[$path] = [$seen[0] + 1, min($seen[1], $at), max($seen[2], $at), $cells[2] === '' ? $seen[3] : $cells[2]];
            $read['notFound']++;

            if (count($missing) > 2 * $keep) {
                $missing = array_slice($missing, -$keep, null, true);
            }
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        $this->addHits($hits);
        $this->addMissing($missing);
        $this->evict($keep);

        return $read;
    }

    /**
     * @param  array<array-key, array{0: int, 1: int}>  $hits  redirect ID => [count, latest hit]
     */
    private function addHits(array $hits): void
    {
        foreach (array_chunk($hits, self::BATCH, true) as $chunk) {
            $values = [];
            $bindings = [];

            foreach ($chunk as $id => [$count, $at]) {
                $values[] = '(?, ?::bigint, to_timestamp(?::double precision))';
                array_push($bindings, (string) $id, $count, $at);
            }

            DB::update(
                'UPDATE redirects AS r SET hits = r.hits + v.n, last_hit_at = GREATEST(r.last_hit_at, v.at) FROM (VALUES '.implode(', ', $values).') AS v(id, n, at) WHERE r.id = v.id',
                $bindings,
            );
        }
    }

    /**
     * @param  array<array-key, array{0: int, 1: int, 2: int, 3: string|null}>  $missing  path => [count, first seen, last seen, referring host]
     */
    private function addMissing(array $missing): void
    {
        foreach (array_chunk($missing, self::BATCH, true) as $chunk) {
            $values = [];
            $bindings = [];

            foreach ($chunk as $path => [$count, $first, $last, $host]) {
                $values[] = '(?, ?::bigint, to_timestamp(?::double precision), to_timestamp(?::double precision), ?)';
                array_push($bindings, (string) $path, $count, $first, $last, $host);
            }

            $rows = implode(', ', $values);

            DB::insert(<<<SQL
                INSERT INTO not_found_log (path, count, first_seen_at, last_seen_at, referrer_host)
                VALUES {$rows}
                ON CONFLICT (path) DO UPDATE SET
                    count = not_found_log.count + EXCLUDED.count,
                    last_seen_at = GREATEST(not_found_log.last_seen_at, EXCLUDED.last_seen_at),
                    referrer_host = COALESCE(EXCLUDED.referrer_host, not_found_log.referrer_host)
                SQL, $bindings);
        }
    }

    /**
     * Lets the least recently seen rows past the cap go.
     */
    private function evict(int $keep): void
    {
        $over = DB::table('not_found_log')->count() - $keep;

        if ($over > 0) {
            DB::delete('DELETE FROM not_found_log WHERE path IN (SELECT path FROM not_found_log ORDER BY last_seen_at, path LIMIT ?)', [$over]);
        }
    }

    /**
     * Two runs' worth of lines read, added together.
     *
     * @param  array{hits: int, notFound: int}  $read
     * @param  array{hits: int, notFound: int}  $more
     *
     * @return array{hits: int, notFound: int}
     */
    private static function added(array $read, array $more): array
    {
        return ['hits' => $read['hits'] + $more['hits'], 'notFound' => $read['notFound'] + $more['notFound']];
    }
}
