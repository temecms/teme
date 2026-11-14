<?php

declare(strict_types=1);

namespace App\Domain\StructuredData;

use App\Domain\Fields\FieldRegistry;

/**
 * A type's property map applied to one item (I-709): each schema.org
 * property read from the first of its fields whose value says something,
 * through that field type's own converter, replacing core's value; a
 * property whose fields are all empty keeps core's. Fields are named by
 * their keys, which never change, never by template strings.
 */
final readonly class PropertyMap
{
    public function __construct(private FieldRegistry $fields) {}

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $stored  the item's stored fields
     *
     * @return array<string, mixed>
     */
    public function apply(array $node, TypeSchema $schema, array $stored): array
    {
        foreach ($schema->properties as $property => $keys) {
            foreach ($keys as $key) {
                $field = $this->fields->find($key);
                $value = $field?->type->structuredData($field, $stored[$key] ?? null);

                if ($value !== null && $value !== '' && $value !== []) {
                    $node[$property] = $value;

                    break;
                }
            }
        }

        return $node;
    }
}
