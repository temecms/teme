<?php

/**
 * Gives each item an import reader read the engine ID it keeps for its site
 * and reader: an ID already given is kept, a new item takes the next one in
 * its counter. So a re-import of a reordered export finds each item where
 * it was, and an added item takes an ID nothing else has. Asked per page of
 * a file, so it loads only the IDs of that page and the counter bounds, not
 * every ID the site already has.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\AssignImportReaderIdsInput;
use App\Domain\Import\Reader\ReaderRows;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

final class AssignImportReaderIds extends BaseOperation
{
    use AuthorizesImports;

    /** Rows per statement, well under PostgreSQL's limit on a statement's values. */
    private const int CHUNK = 500;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var AssignImportReaderIdsInput $input */
        Validator::make(
            ['address' => $input->siteAddress, 'reader' => $input->reader],
            ['address' => ['required', 'string', 'max:2048'], 'reader' => ['required', 'string', 'max:200']],
        )->validate();
    }

    /**
     * The engine ID of each item asked for, by ReaderRows::key().
     *
     * @return array<string, int>
     */
    protected function perform(OperationInput $input): array
    {
        /** @var AssignImportReaderIdsInput $input */
        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['import_reader_ids:'.$input->siteAddress.' '.$input->reader]);

        $ids = $this->existing($input->siteAddress, $input->reader, $input->items);
        [$low, $high] = $this->bounds($input->siteAddress, $input->reader);
        $fresh = [];

        foreach ($input->items as $item) {
            $key = ReaderRows::key($item['kind'], $item['id']);

            if (isset($ids[$key])) {
                continue;
            }

            $counter = ReaderRows::counterOf($item['kind']);

            if (ReaderRows::countsDown($counter)) {
                $ids[$key] = $low[$counter] = min(0, $low[$counter] ?? 0) - 1;
            } else {
                $ids[$key] = $high[$counter] = max(0, $high[$counter] ?? 0) + 1;
            }

            $fresh[] = ['address' => $input->siteAddress, 'reader' => $input->reader, 'object_type' => $item['kind'], 'source_id' => $item['id'], 'counter' => $counter, 'object_id' => $ids[$key]];
        }

        foreach (array_chunk($fresh, self::CHUNK) as $chunk) {
            DB::table('import_reader_ids')->insert($chunk);
        }

        $wanted = [];

        foreach ($input->items as $item) {
            $key = ReaderRows::key($item['kind'], $item['id']);
            $wanted[$key] = $ids[$key];
        }

        return $wanted;
    }

    /**
     * The engine IDs already kept for the items asked for, by ReaderRows::key().
     *
     * @param  list<array{kind: string, id: string}>  $items
     *
     * @return array<string, int>
     */
    private function existing(string $address, string $reader, array $items): array
    {
        $byType = [];

        foreach ($items as $item) {
            $byType[$item['kind']][$item['id']] = true;
        }

        $ids = [];

        foreach ($byType as $type => $sourceIds) {
            foreach (array_chunk(array_keys($sourceIds), self::CHUNK) as $chunk) {
                foreach (DB::table('import_reader_ids')
                    ->where('address', $address)
                    ->where('reader', $reader)
                    ->where('object_type', $type)
                    ->whereIn('source_id', $chunk)
                    ->get(['object_type', 'source_id', 'object_id']) as $row) {
                    $ids[ReaderRows::key((string) $row->object_type, (string) $row->source_id)] = (int) $row->object_id;
                }
            }
        }

        return $ids;
    }

    /**
     * The lowest and highest engine ID already given in each counter for this site and reader.
     *
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function bounds(string $address, string $reader): array
    {
        $low = [];
        $high = [];

        foreach (DB::table('import_reader_ids')
            ->where('address', $address)
            ->where('reader', $reader)
            ->groupBy('counter')
            ->get([DB::raw('counter'), DB::raw('min(object_id) as low'), DB::raw('max(object_id) as high')]) as $row) {
            $low[(string) $row->counter] = (int) $row->low;
            $high[(string) $row->counter] = (int) $row->high;
        }

        return [$low, $high];
    }
}
