<?php

declare(strict_types=1);

namespace Teme\Contracts\Admin;

use InvalidArgumentException;

/**
 * What a table asks of a record's rows: its page, how many a page, the
 * search, the chosen filters, and the sorted column and direction.
 *
 * @since 0.9.0
 */
final readonly class TableQuery
{
    /**
     * @param  array<string, string>  $filters  by declared filter id
     * @param  string  $direction  asc or desc
     *
     * @throws InvalidArgumentException for a page below 1, a page size outside 1 to 100, or another direction
     *
     * @since 0.9.0
     */
    public function __construct(
        public int $page,
        public int $perPage,
        public string $search,
        public array $filters,
        public ?string $sort,
        public string $direction,
    ) {
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('A table asks for page 1 or later, of 1 to 100 rows.');
        }

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidArgumentException('A table sorts asc or desc.');
        }
    }
}
