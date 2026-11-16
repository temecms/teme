<?php

declare(strict_types=1);

namespace Teme\Contracts\Admin;

use InvalidArgumentException;

/**
 * One page of a record's rows, and how many there are in all when the
 * plugin knows.
 *
 * @since 0.9.0
 */
final readonly class RowPage
{
    /**
     * @param  list<Row>  $rows
     *
     * @throws InvalidArgumentException for more than 100 rows or a negative total
     *
     * @since 0.9.0
     */
    public function __construct(
        public array $rows,
        public ?int $total = null,
    ) {
        if (count($rows) > 100) {
            throw new InvalidArgumentException('A page holds at most 100 rows.');
        }

        if ($total !== null && $total < 0) {
            throw new InvalidArgumentException('A total can’t be negative.');
        }
    }
}
