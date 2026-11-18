<?php

/**
 * What the editor is told about the fields of one content type: each field's
 * label and rules for the browser, where it is drawn, and which control draws
 * it with what settings, from its type's edit().
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields;

final class FieldManifest
{
    /**
     * The editor's fields prop for a content type, in registration order.
     *
     * @return list<array{key: string, handle: string, label: string, description: string, type: string, required: bool, maxLength: int|null, options: array<string, string>, place: string, component: string|null, settings: array<string, mixed>}>
     */
    public static function forEditor(string $type): array
    {
        return array_map(function (Field $field): array {
            $control = $field->type->edit($field);

            return [
                'key' => $field->key,
                'handle' => $field->handle,
                'label' => $field->label,
                'description' => $field->description,
                'type' => $field->type->name(),
                'required' => $field->required,
                'maxLength' => $field->maxLength,
                'options' => $field->options,
                'place' => $field->place->value,
                'component' => $control['component'],
                'settings' => $control['settings'],
            ];
        }, app(FieldRegistry::class)->forType($type));
    }
}
