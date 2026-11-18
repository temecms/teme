<?php

declare(strict_types=1);

namespace Teme\Contracts\StructuredData;

use Teme\Contracts\Head\HeadPage;

/**
 * A plugin granted pages.head that adds nodes to public pages' JSON-LD
 * graph. Core merges them with its own by @id, keeps only the schema.org
 * terms on the graph's list and those the plugin declares, and leaves out a
 * node lacking what search engines require.
 *
 * @since 1.1.0
 */
interface HasStructuredData
{
    /**
     * Its nodes for this page, at most 50, answered within 100 ms; none
     * where it adds nothing. A node that names one of core's by its @id
     * (see NodeIds) adds to it: an empty value is filled, lists are joined,
     * and a value core already gives stays.
     *
     * @return list<GraphNode>
     *
     * @since 1.1.0
     */
    public function graphNodes(HeadPage $page): array;
}
