<?php

/**
 * One list a phase reads from the old site: a route of the export API, its
 * query, a page's size, and the item keys whose change means nothing. A
 * whole list is one answer's key, read in one go (menus).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases;

use InvalidArgumentException;

final readonly class ImportList
{
    public bool $paged;

    /**
     * @param  array<string, scalar>  $query
     * @param  list<string>  $ignore  top-level item keys left out of its hash
     */
    public function __construct(
        public string $name,
        public array $query = [],
        public int $limit = 100,
        public array $ignore = [],
        public ?string $itemsAt = null,
    ) {
        if (preg_match('/^[a-z]+$/', $name) !== 1) {
            throw new InvalidArgumentException("An import list's name [{$name}] must be lower-case letters.");
        }

        if ($limit < 1 || $limit > 2000) {
            throw new InvalidArgumentException("An import list's limit [{$limit}] must be between 1 and 2000.");
        }

        $this->paged = $itemsAt === null;
    }

    /**
     * A list read whole: the items under one key of the route's answer.
     *
     * @param  array<string, scalar>  $query
     */
    public static function whole(string $name, string $itemsAt, array $query = []): self
    {
        return new self($name, $query, 1, [], $itemsAt);
    }
}
