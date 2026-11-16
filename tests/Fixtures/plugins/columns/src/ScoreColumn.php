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

        if ($this->mode === 'wrong') {
            $first = $rows->rows[0]->id ?? 'x';

            return [
                'unknown-row' => Cell::badge('x'),
                $first => Cell::number(1),
                ($rows->rows[1]->id ?? 'y') => 'plain',
                ($rows->rows[2]->id ?? $first) => Cell::badge('Kept', 'ok'),
            ];
        }

        $cells = [];

        foreach ($rows->rows as $row) {
            $cells[$row->id] = Cell::badge('Good', 'ok');
        }

        return $cells;
    }
}
