<?php

/**
 * A taxonomy's terms as the management API lists them, so an outside tool
 * can name an item's terms by ID.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\ManageApi\Queries;

use App\Domain\ManageApi\ManageApiProblem;
use App\Domain\ManageApi\ManageApiWords;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Types\TaxonomyRegistry;

final readonly class TermChoices
{
    public function __construct(private TaxonomyRegistry $taxonomies) {}

    /**
     * Returns every term of the taxonomy, by name, with its ID, name, slug
     * and parent's ID; refused as not_found when the site has no such taxonomy.
     *
     * @return list<array{id: string, name: string, slug: string, parent: string|null}>
     */
    public function of(string $taxonomy): array
    {
        if (! $this->taxonomies->has($taxonomy)) {
            throw ManageApiProblem::notFound(ManageApiWords::noTaxonomy($taxonomy));
        }

        return Term::query()
            ->where('taxonomy', $taxonomy)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'parent_id'])
            ->map(fn (Term $term): array => [
                'id' => (string) $term->id,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'parent' => $term->parent_id === null ? null : (string) $term->parent_id,
            ])
            ->values()
            ->all();
    }
}
