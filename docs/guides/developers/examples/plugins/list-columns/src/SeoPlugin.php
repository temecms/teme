<?php

declare(strict_types=1);

namespace TemeExamples\ListColumns;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Lists\HasListColumns;
use Teme\Contracts\Lists\ListColumn;
use Teme\Contracts\Lists\ListRows;
use Teme\Contracts\Plugins\Plugin;

/**
 * The handbook's list-columns example: one badge column filled from the
 * plugin's own table on ContentSaved.
 */
final class SeoPlugin implements HasListColumns, Plugin
{
    public function listColumns(): array
    {
        return [
            'score' => new class implements ListColumn
            {
                public function cells(ListRows $rows): array
                {
                    $cells = [];

                    foreach ($rows->rows as $row) {
                        // Read from the plugin's own table, filled on ContentSaved.
                        $cells[$row->id] = Cell::badge('Good', 'ok');
                    }

                    return $cells;
                }
            },
        ];
    }

    public function register(Hooks $hooks): void
    {
        // Fill the plugin's own table on ContentSaved.
    }
}
