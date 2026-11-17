<?php

declare(strict_types=1);

namespace Teme\Contracts\Admin;

use InvalidArgumentException;
use Teme\Contracts\Lists\Cell;

/**
 * One record's row in a table: its id, its cells by declared column id, and
 * the actions not offered on it, such as Enable on one already enabled.
 *
 * @since 0.9.0
 */
final readonly class Row
{
    /**
     * @param  array<string, Cell>  $cells
     * @param  list<string>  $unavailable  action ids not offered on this row
     *
     * @throws InvalidArgumentException for an empty or over-long id
     *
     * @since 0.9.0
     */
    public function __construct(
        public string $id,
        public array $cells,
        public array $unavailable = [],
    ) {
        if ($id === '' || strlen($id) > 64) {
            throw new InvalidArgumentException('A row’s id must be 1 to 64 characters.');
        }
    }
}
