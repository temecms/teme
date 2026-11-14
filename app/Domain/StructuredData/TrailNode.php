<?php

declare(strict_types=1);

namespace App\Domain\StructuredData;

use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\ViewModels\Crumb;
use Teme\Contracts\StructuredData\NodeIds;

/**
 * The trail a theme draws, as search engines read it (I-702): a
 * BreadcrumbList of the same steps, each at its position, every step but
 * the last with its address; nothing for a trail of fewer than two steps,
 * which Google doesn't show.
 */
final class TrailNode
{
    /**
     * @param  list<Crumb>|null  $trail
     */
    public static function for(?array $trail, string $page): ?JsonLdNode
    {
        if ($trail === null || count($trail) < 2) {
            return null;
        }

        $last = count($trail) - 1;
        $items = [];

        foreach ($trail as $position => $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position + 1,
                'name' => $crumb->name,
                'item' => $position === $last || $crumb->url === '' ? null : GraphAddresses::absolute($crumb->url),
            ];
        }

        return new JsonLdNode(['@id' => $page.NodeIds::BREADCRUMB, '@type' => 'BreadcrumbList', 'itemListElement' => $items]);
    }
}
