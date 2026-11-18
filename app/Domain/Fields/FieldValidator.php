<?php

declare(strict_types=1);

namespace App\Domain\Fields;

use App\Domain\Plugins\Seo\PluginSearchFields;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Checks the fields submitted for one content item against the registry and
 * returns them as they are stored: known keys only, each value normalised.
 * Missing fields are not filled in — defaults resolve when read. A
 * plugin's Search and social values are checked by PluginSearchFields.
 */
final class FieldValidator
{
    public function __construct(private readonly FieldRegistry $registry) {}

    /**
     * The fields as they are stored after this save: those sent, checked
     * where they differ from what the item holds, plus every removed field's
     * value from what the item holds.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $current  the fields the item holds now
     *
     * @return array<string, mixed>
     */
    public function validate(string $contentType, array $fields, array $current = []): array
    {
        // A plugin's Search and social values, one object under its key (docs/guides/developers/plugins/seo.md).
        $plugins = app(PluginSearchFields::class)->validate(
            $contentType,
            array_filter($fields, PluginSearchFields::isPluginKey(...), ARRAY_FILTER_USE_KEY),
            array_filter($current, PluginSearchFields::isPluginKey(...), ARRAY_FILTER_USE_KEY),
        );
        $fields = array_filter($fields, fn (string $key): bool => ! PluginSearchFields::isPluginKey($key), ARRAY_FILTER_USE_KEY);
        $current = array_filter($current, fn (string $key): bool => ! PluginSearchFields::isPluginKey($key), ARRAY_FILTER_USE_KEY);
        $declared = [];

        foreach ($this->registry->forType($contentType) as $field) {
            $declared[$field->key] = $field;
        }

        // A removed field's value travels with the item untouched, so Restore
        // finds it (docs/studies/content-and-fields.md §5.3): taken from what the
        // item stores, never from the request, which may send or omit it.
        $removed = array_diff_key(array_flip($this->registry->storedKeys($contentType)), $declared);
        $kept = array_intersect_key($current, $removed);
        $fields = array_diff_key($fields, $removed);

        $unknown = array_diff(array_keys($fields), array_keys($declared));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'fields' => __('These fields do not exist on a :type: :keys', ['type' => $contentType, 'keys' => implode(', ', $unknown)]),
            ]);
        }

        $rules = [];

        foreach ($fields as $key => $value) {
            // A value as the item already stores it was checked when stored: a
            // choice taken off the list stays, shown as itself.
            if (array_key_exists($key, $current) && $current[$key] === $value) {
                continue;
            }

            $rules[$key] = $declared[$key]->type->rules($declared[$key]);
        }

        Validator::make($fields, $rules)->validate();

        $stored = [];

        foreach ($fields as $key => $value) {
            $stored[$key] = array_key_exists($key, $current) && $current[$key] === $value
                ? $value
                : $declared[$key]->type->normalise($declared[$key], $value);
        }

        return [...$stored, ...$kept, ...$plugins];
    }
}
