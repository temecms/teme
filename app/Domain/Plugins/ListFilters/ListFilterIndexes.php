<?php

/**
 * Checks, when a plugin is enabled or updated and inside that transaction, that
 * each list filter it declares reads a column its own table has, and that the
 * column leads a valid index, so a filter on a long list stays an indexed lookup.
 * Returns the sentence that refuses the enable, or null when every filter is sound.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\ListFilters;

use App\Domain\Plugins\Manifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final readonly class ListFilterIndexes
{
    /**
     * The first declared filter that reads a table, column or item column the
     * plugin's table doesn't have, or a column no valid index leads with; null
     * when none does.
     */
    public function refusal(Manifest $manifest): ?string
    {
        foreach ($manifest->contributes()['listFilters'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $table = (string) $entry['table'];
            $column = (string) $entry['column'];
            $label = (string) $entry['label'];

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasColumn($table, (string) $entry['item'])) {
                return __('The list filter “:label” reads :table.:column, which the table doesn’t have.', ['label' => $label, 'table' => $table, 'column' => $column]);
            }

            if (! $this->leadsValidIndex($table, $column)) {
                return __('The list filter “:label” reads :table.:column, which no valid index leads with, so the plugin can’t be enabled.', ['label' => $label, 'table' => $table, 'column' => $column]);
            }
        }

        return null;
    }

    /**
     * Whether a valid, complete index on the table leads with the column.
     */
    private function leadsValidIndex(string $table, string $column): bool
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT CASE WHEN EXISTS (
                SELECT 1
                FROM pg_index i
                JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
                WHERE i.indrelid = to_regclass(?)
                  AND a.attname = ?
                  AND i.indisvalid
                  AND i.indisready
                  AND i.indpred IS NULL
            ) THEN 1 ELSE 0 END AS found
            SQL, [$table, $column]);

        return (int) $row->found === 1;
    }
}
