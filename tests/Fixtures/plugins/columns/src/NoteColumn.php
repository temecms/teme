<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Columns;

use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Lists\ListColumn;
use Teme\Contracts\Lists\ListRows;

/**
 * Text column that returns a note, or misbehaves per mode.
 */
final class NoteColumn implements ListColumn
{
    public function __construct(private readonly string $mode) {}

    public function cells(ListRows $rows): array
    {
        ColumnMode::apply($this->mode, $rows);

        $cells = [];

        foreach ($rows->rows as $row) {
            $cells[$row->id] = match ($this->mode) {
                'markup' => Cell::text('<img src=x onerror=alert(1)>'),
                'wrong' => Cell::number(1),
                default => Cell::text('A note'),
            };
        }

        if ($this->mode === 'wrong') {
            return [
                'unknown-row' => Cell::badge('x'),
                ($rows->rows[0]->id ?? 'x') => 'plain',
                ...$cells,
            ];
        }

        return $cells;
    }
}
