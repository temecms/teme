<?php

/**
 * One page of ContentReader::published(): its items, and the cursor for the
 * next page.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

/**
 * One page of ContentReader::published(): its items, and the cursor for the
 * next page.
 *
 * @since 1.0.0
 */
final readonly class ItemPage
{
    /**
     * @param  list<ItemRef>  $items
     * @param  string|null  $next  the cursor for the next page; null on the last page
     *
     * @since 1.0.0
     */
    public function __construct(
        public array $items,
        public ?string $next,
    ) {}
}
