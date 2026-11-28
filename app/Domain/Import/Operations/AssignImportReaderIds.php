<?php

/**
 * Gives each item an import reader read the engine ID it keeps for its site
 * and reader: an ID already given is kept, a new item takes the next one in
 * its counter. So a re-import of a reordered export finds each item where
 * it was, and an added item takes an ID nothing else has.
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

        $ids = [];
        $low = [];
        $high = [];

        foreach (DB::table('import_reader_ids')->where('address', $input->siteAddress)->where('reader', $input->reader)->get(['object_type', 'source_id', 'counter', 'object_id']) as $row) {
            $ids[ReaderRows::key((string) $row->object_type, (string) $row->source_id)] = (int) $row->object_id;
            $low[$row->counter] = min($low[$row->counter] ?? 0, (int) $row->object_id);
            $high[$row->counter] = max($high[$row->counter] ?? 0, (int) $row->object_id);
        }

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
}
