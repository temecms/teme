<?php

/**
 * One top-level block in a comparison of two revisions: whether it stayed,
 * arrived, left, changed or moved, and its text before and after. The text
 * is escaped HTML; a changed paragraph's `after` marks its words with
 * `<ins>` and `<del>`.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

final readonly class DiffBlock
{
    /**
     * @param  'same'|'added'|'removed'|'changed'|'moved'  $kind
     */
    public function __construct(
        public string $kind,
        public ?string $before,
        public ?string $after,
        public string $node,
    ) {}
}
