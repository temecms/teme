<?php

/**
 * One page of a content list, in the list's order, for a column to fill.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Lists;

/**
 * One page of a content list, in the list's order, for a column to fill.
 *
 * @since 1.0.0
 */
final readonly class ListRows
{
    /**
     * @param  list<ListRow>  $rows  in the list's order
     *
     * @since 1.0.0
     */
    public function __construct(
        public string $type,
        public array $rows,
    ) {}

    /**
     * The row ids in the list's order.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    public function ids(): array
    {
        return array_map(static fn (ListRow $row): string => $row->id, $this->rows);
    }
}
