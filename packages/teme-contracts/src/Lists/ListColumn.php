<?php

/**
 * A plugin's handler for one declared list column.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Lists;

/**
 * A plugin's handler for one declared list column.
 *
 * @since 1.0.0
 */
interface ListColumn
{
    /**
     * One page of a list's rows; the cells by row id. A row left out shows an empty cell.
     *
     * @return array<string, Cell>
     *
     * @since 1.0.0
     */
    public function cells(ListRows $rows): array;
}
