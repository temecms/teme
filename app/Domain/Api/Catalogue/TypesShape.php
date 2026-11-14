<?php

declare(strict_types=1);

namespace App\Domain\Api\Catalogue;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Api\Envelope;
use App\Domain\Api\FieldList;
use App\Domain\Fields\Field;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;

/**
 * The public content types as the API lists them (D28): each with its
 * archive page, its public taxonomies and the fields it shows, which come
 * from the one rule an item's values read too, so the two cannot disagree.
 */
final readonly class TypesShape
{
    public function __construct(private ContentTypeRegistry $types, private TaxonomyRegistry $taxonomies) {}

    /**
     * The `data` member: every public type, in the registry's order.
     *
     * @return list<array<string, mixed>>
     */
    public function data(): array
    {
        $public = array_values(array_filter($this->types->all(), fn (ContentType $type): bool => $type->public));

        return array_map($this->type(...), $public);
    }

    /**
     * The `links` member.
     *
     * @return array{self: string}
     */
    public function links(): array
    {
        return ['self' => Envelope::link('api.v1.types', [])];
    }

    /**
     * One type as the API gives it.
     *
     * @return array<string, mixed>
     */
    private function type(ContentType $type): array
    {
        $archive = TypeArchives::path($type->key);
        $path = $archive === null ? null : Addresses::toUrl($archive);

        return [
            'key' => $type->key,
            'singular' => $type->singular,
            'plural' => $type->plural,
            'hierarchical' => $type->hierarchical,
            'archive' => $path === null ? null : ['path' => $path, 'url' => rtrim(url('/'), '/').$path],
            'taxonomies' => array_values(array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, array_filter($this->taxonomies->forType($type->key), fn (Taxonomy $taxonomy): bool => $taxonomy->public))),
            'fields' => array_map($this->field(...), FieldList::shown($type->key)),
            'links' => ['items' => SiteShape::itemsLink(['type' => $type->key])],
        ];
    }

    /**
     * One field: its handle, label and type name; its choices when it is a
     * select, null for every other type.
     *
     * @return array{handle: string, label: string, type: string, options: list<array{value: string, label: string}>|null}
     */
    private function field(Field $field): array
    {
        $type = FieldList::typeName($field);
        $options = [];

        foreach ($field->options as $value => $label) {
            // The empty choice is the admin's way of showing no value; the API gives that as null.
            if ((string) $value === '') {
                continue;
            }

            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return ['handle' => $field->handle, 'label' => $field->label, 'type' => $type, 'options' => $type === 'select' ? $options : null];
    }
}
