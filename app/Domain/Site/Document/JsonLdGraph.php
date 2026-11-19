<?php

/**
 * A page's JSON-LD graph as it is written, and the nodes the gate left out
 * of it (I-703).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Document;

final readonly class JsonLdGraph
{
    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<Withheld>  $withheld
     */
    public function __construct(public array $nodes, public array $withheld) {}
}
