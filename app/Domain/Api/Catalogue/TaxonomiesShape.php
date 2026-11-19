<?php

/**
 * The public taxonomies as the API lists them (D28): each with the public
 * types that use it and the address of its terms.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api\Catalogue;

use App\Domain\Api\Envelope;
use App\Domain\Types\ContentType;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;

final readonly class TaxonomiesShape
{
    public function __construct(private TaxonomyRegistry $taxonomies) {}

    /**
     * The `data` member: every public taxonomy, in the registry's order.
     *
     * @return list<array<string, mixed>>
     */
    public function data(): array
    {
        $public = array_values(array_filter($this->taxonomies->all(), fn (Taxonomy $taxonomy): bool => $taxonomy->public));

        return array_map(fn (Taxonomy $taxonomy): array => [
            'key' => $taxonomy->key,
            'singular' => $taxonomy->singular,
            'plural' => $taxonomy->plural,
            'hierarchical' => $taxonomy->hierarchical,
            'types' => array_values(array_map(fn (ContentType $type): string => $type->key, array_filter($this->taxonomies->typesUsing($taxonomy->key), fn (ContentType $type): bool => $type->public))),
            'links' => ['terms' => SiteShape::under('terms', ['taxonomy' => $taxonomy->key])],
        ], $public);
    }

    /**
     * The `links` member.
     *
     * @return array{self: string}
     */
    public function links(): array
    {
        return ['self' => Envelope::link('api.v1.taxonomies', [])];
    }
}
