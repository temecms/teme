<?php

/**
 * What the document shell draws from DocumentMeta: each key's meta tag and
 * link, and the page's merged JSON-LD graph, with the nodes the gate left out
 * of it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Document;

final readonly class DocumentHead
{
    /**
     * @param  list<MetaTag>  $meta
     * @param  list<LinkTag>  $links
     * @param  list<array<string, mixed>>  $graph
     * @param  list<Withheld>  $withheld  the nodes the gate left out, for the editor's checks (I-703)
     */
    public function __construct(public array $meta, public array $links, public array $graph, public array $withheld = []) {}
}
