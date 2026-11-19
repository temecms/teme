<?php

/**
 * The page's typed head (I-228): every head contributor's entries, read
 * plugin, then site, then core, each key's first entry kept and nodes
 * sharing an @id merged, then filtered and tidied, for the document shell to draw. Internal until the
 * plugin API publishes its contract.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Document;

use Illuminate\Contracts\Container\Container;

final readonly class DocumentMeta
{
    /** The container tag a head contributor is registered under, from its own provider's register(). */
    public const string CONTRIBUTORS = 'teme.document.head';

    /** The container tag a graph filter is registered under (I-697), from its own provider's register(). */
    public const string GRAPH_FILTERS = 'teme.structured-data.graph';

    public function __construct(private Container $container) {}

    /**
     * Every contributor's entries for this page, in the order they were
     * read: the meta tags, the links and the merged graph.
     */
    public function for(DocumentPage $page): DocumentHead
    {
        $meta = [];
        $links = [];
        $nodes = [];

        foreach (Precedence::order($this->container->tagged(self::CONTRIBUTORS), HeadContributor::class) as $contributor) {
            foreach ($contributor->entries($page) as $entry) {
                if ($entry instanceof MetaTag) {
                    $meta[$entry->identity()] ??= $entry;
                } elseif ($entry instanceof LinkTag) {
                    $links[$entry->identity()] ??= $entry;
                } else {
                    $nodes[] = $entry;
                }
            }
        }

        $graph = JsonLd::build($nodes, fn (array $merged): array => $this->filtered($merged, $page));

        return new DocumentHead(array_values($meta), array_values($links), $graph->nodes, $graph->withheld);
    }

    /**
     * The merged graph through every graph filter, in precedence order.
     *
     * @param  list<array<string, mixed>>  $graph
     *
     * @return list<array<string, mixed>>
     */
    private function filtered(array $graph, DocumentPage $page): array
    {
        foreach (Precedence::order($this->container->tagged(self::GRAPH_FILTERS), GraphFilter::class) as $filter) {
            $graph = $filter->filter($graph, $page);
        }

        return $graph;
    }
}
