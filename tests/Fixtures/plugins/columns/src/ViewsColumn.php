<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Columns;

use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Lists\ListColumn;
use Teme\Contracts\Lists\ListRows;

/**
 * Number column that returns a view count, or misbehaves per mode.
 */
final class ViewsColumn implements ListColumn
{
    public function __construct(private readonly string $mode) {}

    public function cells(ListRows $rows): array
    {
        ColumnMode::apply($this->mode, $rows);

        $cells = [];

        foreach ($rows->rows as $index => $row) {
            $cells[$row->id] = Cell::number(100 + $index);
        }

        return $cells;
    }
}
