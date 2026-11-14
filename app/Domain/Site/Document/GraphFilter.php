<?php

declare(strict_types=1);

namespace App\Domain\Site\Document;

/**
 * Something that may change the page's merged graph before core tidies it
 * (I-697): the only way to remove or rewrite another's node. Registered
 * under DocumentMeta::GRAPH_FILTERS from its part's own provider; run once a
 * page, plugin first, then site, then core, each given what the one before
 * returned. What it returns is held to a node's rules.
 */
interface GraphFilter
{
    /**
     * Whom it speaks for, which decides when it runs.
     */
    public function precedence(): Precedence;

    /**
     * The graph as it should be: its nodes kept, rewritten, removed or
     * added, each still with an absolute @id and an @type.
     *
     * @param  list<array<string, mixed>>  $graph
     *
     * @return list<array<string, mixed>>
     */
    public function filter(array $graph, DocumentPage $page): array;
}
