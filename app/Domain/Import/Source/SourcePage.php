<?php

/**
 * One page of a source's list: its items, and the ID the next page starts
 * after, or null when the list ends.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Source;

final readonly class SourcePage
{
    /**
     * @param  array<int|string, array<string, mixed>>  $items
     */
    public function __construct(
        public array $items,
        public ?int $nextAfterId,
    ) {}
}
