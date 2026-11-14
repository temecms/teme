<?php

declare(strict_types=1);

namespace App\Domain\Taxonomy;

use App\Domain\Types\TaxonomyRegistry;

/**
 * The category that stands for an item (I-869): its chosen primary
 * category while that is one of its terms in its type's first hierarchical
 * taxonomy, else the first of those in its order. It puts the item in its
 * address, its section label and its trail, so all three agree.
 */
final class PrimaryCategory
{
    /**
     * The term ID that stands for the item, or null when it has none.
     *
     * @param  list<string>  $termIds  the item's terms in its type's first hierarchical taxonomy, in their order
     * @param  mixed  $chosen  the primary category field's stored value
     */
    public static function pick(array $termIds, mixed $chosen): ?string
    {
        return is_string($chosen) && in_array($chosen, $termIds, true) ? $chosen : ($termIds[0] ?? null);
    }

    /**
     * The taxonomy an item of this type takes its primary category from:
     * its type's first hierarchical taxonomy, or null when it uses none. The
     * one place that decides it, for the address's {category}, the card's
     * kicker and the trail (I-869).
     */
    public static function taxonomyFor(string $type): ?string
    {
        foreach (app(TaxonomyRegistry::class)->forType($type) as $taxonomy) {
            if ($taxonomy->hierarchical) {
                return $taxonomy->key;
            }
        }

        return null;
    }
}
