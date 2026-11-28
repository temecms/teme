<?php

/**
 * One page of items a reader lists, and the cursor the next page starts
 * after, or null when the list ends.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Import;

/**
 * A page of items. Cursors are opaque to core: it passes back what the
 * reader gave it and nothing else.
 *
 * @since 1.8.0
 */
final readonly class ImportPage
{
    /**
     * @param  list<ImportItem>  $items
     *
     * @since 1.8.0
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {}
}
