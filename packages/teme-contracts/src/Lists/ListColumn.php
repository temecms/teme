<?php

declare(strict_types=1);

namespace Teme\Contracts\Lists;

/**
 * A plugin's handler for one declared list column.
 *
 * @since 0.9.0
 */
interface ListColumn
{
    /**
     * One page of a list's rows; the cells by row id. A row left out shows an empty cell.
     *
     * @return array<string, Cell>
     *
     * @since 0.9.0
     */
    public function cells(ListRows $rows): array;
}
