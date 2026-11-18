<?php

declare(strict_types=1);

namespace App\Domain\Cards;

/**
 * One top-level block in a comparison of two revisions: whether it stayed,
 * arrived, left, changed or moved, and its text before and after. The text
 * is escaped HTML; a changed paragraph's `after` marks its words with
 * `<ins>` and `<del>` in one text, and `markedBefore` and `markedAfter`
 * mark each side's own: the removed words in the one, the added in the other.
 */
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
        public ?string $markedBefore = null,
        public ?string $markedAfter = null,
        public int $added = 0,
        public int $removed = 0,
        /** What a card is called; null for prose. */
        public ?string $label = null,
    ) {}
}
