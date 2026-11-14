<?php

declare(strict_types=1);

namespace App\Domain\Cards\Imported;

/**
 * What MarkupCleaner made of one chunk of HTML: the tree an Imported content
 * card holds, and what it removed, counted by name for the import's report
 * (I-329).
 */
final readonly class CleanedMarkup
{
    /**
     * @param  list<array<string, mixed>>  $parts  the tree, as the card's `markup` holds it
     * @param  array<string, int>  $removed  by an element's name, or `style-attribute`, `event-attribute`, `link` or `image`
     */
    public function __construct(
        public array $parts,
        public array $removed,
    ) {}

    /**
     * Whether a card can hold the tree: it has something in it, and it is
     * within the card's limits of 2,000 parts and 12 levels.
     */
    public function fits(): bool
    {
        return $this->parts !== [] && MarkupTree::problem($this->parts) === null;
    }

    /**
     * The tree's words on one line, as the card gives them to search.
     */
    public function text(): string
    {
        return MarkupTree::text($this->parts);
    }
}
