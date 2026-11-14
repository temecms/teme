<?php

declare(strict_types=1);

namespace App\Domain\Fields\Types;

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldType;
use Illuminate\Validation\Rule;

/**
 * One choice from the field's options, stored as the option's value.
 */
final class SelectField implements FieldType
{
    public function name(): string
    {
        return 'select';
    }

    public function rules(Field $field): array
    {
        return ['nullable', Rule::in(array_keys($field->options))];
    }

    public function default(Field $field): mixed
    {
        return array_key_first($field->options);
    }

    public function normalise(Field $field, mixed $value): mixed
    {
        return (string) $value;
    }

    /** The option's value, never its label. */
    public function structuredData(Field $field, mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The control and its options as an ordered list of strings: PHP keeps a
     * key such as '10' as an integer, which a browser could reorder or retype.
     */
    public function edit(Field $field): array
    {
        $options = [];

        foreach ($field->options as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return ['component' => 'select', 'settings' => ['options' => $options]];
    }

    /**
     * The chosen option's value and label, or null for the empty choice; a
     * value that is not an option gives itself as its label.
     *
     * @return array{value: string, label: string}|null
     */
    public function render(Field $field, mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return ['value' => $value, 'label' => $field->options[$value] ?? $value];
    }

    /** Text. */
    public function sqlType(Field $field): string
    {
        return 'text';
    }

    /** Nothing: a choice points at nothing. */
    public function references(Field $field, mixed $value): array
    {
        return [];
    }

    /** The chosen option's label, or the empty string for the empty choice. */
    public function text(Field $field, mixed $value): string
    {
        $chosen = $this->render($field, $value);

        return $chosen === null ? '' : $chosen['label'];
    }
}
