<?php

declare(strict_types=1);

namespace App\Domain\Site\Document;

/**
 * A page's JSON-LD graph as it is written, and the nodes the gate left out
 * of it (I-703).
 */
final readonly class JsonLdGraph
{
    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<Withheld>  $withheld
     */
    public function __construct(public array $nodes, public array $withheld) {}
}
