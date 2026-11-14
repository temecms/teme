<?php

declare(strict_types=1);

namespace App\Domain\Cards;

use App\Domain\Site\Document\JsonLdNode;

/**
 * A card that adds nodes to its page's structured data (I-704), from its
 * own attributes, never from its HTML. Optional: a card that doesn't
 * implement it adds nothing; core's Video card gives a VideoObject with its
 * poster.
 */
interface StructuredDataCard
{
    /**
     * Its nodes for the page at $page, each @id that address and
     * NodeIds::card(); none when its attributes can't describe what it
     * shows.
     *
     * @param  array<string, mixed>  $attrs
     *
     * @return list<JsonLdNode>
     */
    public function structuredData(array $attrs, string $page): array;
}
