<?php

/**
 * Checks the list filters a plugin declares against the tables its own migrations
 * created: each reads a table the plugin created, a text column and a text item
 * column, and the column leads a plain, valid, non-partial btree or hash index.
 * Run when a plugin is enabled and when its database changes run, inside that
 * transaction, so a refusal undoes the migrations too. Also answers, cheaply and
 * per request, whether a filter's table and columns are still there.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\ListFilters;

use App\Domain\Plugins\Manifest;
use Illuminate\Support\Facades\DB;

final readonly class ListFilterIndexes
{
    /** The column types a filter may match on or key its rows by. */
    private const array TEXT_TYPES = ['text', 'varchar', 'bpchar'];

    /**
     * The first declared filter the check refuses, in a plain sentence, or null
     * when every filter is sound. $owned lists the tables the plugin's own
     * migrations created and still have.
     *
     * @param  list<string>  $owned
     */
    public function refusal(Manifest $manifest, array $owned): ?string
    {
        foreach ($manifest->contributes()['listFilters'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $label = (string) $entry['label'];
            $table = (string) $entry['table'];
            $column = (string) $entry['column'];
            $item = (string) $entry['item'];

            if (! in_array($table, $owned, true)) {
                return __('The list filter “:label” reads :table, which the plugin’s own migrations don’t create.', ['label' => $label, 'table' => $table]);
            }

            $types = $this->columnTypes($table);

            if (! isset($types[$column], $types[$item])) {
                return __('The list filter “:label” reads :table.:column, which the table doesn’t have.', ['label' => $label, 'table' => $table, 'column' => $column]);
            }

            if (! in_array($types[$column], self::TEXT_TYPES, true)) {
                return __('The list filter “:label” reads :table.:column, which isn’t text, so the filter can’t be used.', ['label' => $label, 'table' => $table, 'column' => $column]);
            }

            if (! in_array($types[$item], self::TEXT_TYPES, true)) {
                return __('The list filter “:label” keys its rows by :table.:item, which isn’t text, so the filter can’t be used.', ['label' => $label, 'table' => $table, 'item' => $item]);
            }

            if (! $this->leadsValidIndex($table, $column)) {
                return __('The list filter “:label” reads :table.:column, which no valid index leads with, so the filter can’t be used.', ['label' => $label, 'table' => $table, 'column' => $column]);
            }
        }

        return null;
    }

    /**
     * Whether a filter's table, column and item column are still there, as the
     * plugin's migrations left them. Checked on each request, so a table dropped
     * after enabling drops its filter rather than failing every list.
     */
    public function stillThere(string $table, string $column, string $item): bool
    {
        $types = $this->columnTypes($table);

        return isset($types[$column], $types[$item]);
    }

    /**
     * The table's columns and their types, by name; none when the table isn't there.
     *
     * @return array<string, string>
     */
    private function columnTypes(string $table): array
    {
        $types = [];

        foreach (DB::select(<<<'SQL'
            SELECT a.attname AS name, t.typname AS type
            FROM pg_attribute a
            JOIN pg_type t ON t.oid = a.atttypid
            WHERE a.attrelid = to_regclass(?)
              AND a.attnum > 0
              AND NOT a.attisdropped
            SQL, [$table]) as $row) {
            $types[(string) $row->name] = (string) $row->type;
        }

        return $types;
    }

    /**
     * Whether a valid, complete, non-partial btree or hash index on the table leads with the column.
     */
    private function leadsValidIndex(string $table, string $column): bool
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT CASE WHEN EXISTS (
                SELECT 1
                FROM pg_index i
                JOIN pg_class ic ON ic.oid = i.indexrelid
                JOIN pg_am am ON am.oid = ic.relam
                JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
                WHERE i.indrelid = to_regclass(?)
                  AND a.attname = ?
                  AND i.indisvalid
                  AND i.indisready
                  AND i.indpred IS NULL
                  AND am.amname IN ('btree', 'hash')
            ) THEN 1 ELSE 0 END AS found
            SQL, [$table, $column]);

        return (int) $row->found === 1;
    }
}
