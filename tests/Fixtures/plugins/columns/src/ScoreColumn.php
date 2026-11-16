<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Columns;

use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Lists\ListColumn;
use Teme\Contracts\Lists\ListRows;

/**
 * Badge column that returns an SEO score, or misbehaves per mode.
 */
final class ScoreColumn implements ListColumn
{
    public function __construct(private readonly string $mode) {}

    public function cells(ListRows $rows): array
    {
        ColumnMode::apply($this->mode, $rows);

        $cells = [];

        foreach ($rows->rows as $row) {
            $cells[$row->id] = Cell::badge('Good', 'ok');
        }

        return $cells;
    }
}
