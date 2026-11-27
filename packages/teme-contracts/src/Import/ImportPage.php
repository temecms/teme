<?php

/**
 * One page of items a reader lists, and the cursor the next page starts
 * after, or null when the list ends.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Import;

/**
 * A page of items. Cursors are opaque to core: it passes back what the
 * reader gave it and nothing else.
 *
 * @since next
 */
final readonly class ImportPage
{
    /**
     * @param  list<ImportItem>  $items
     *
     * @since next
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {}
}
