<?php

declare(strict_types=1);

namespace App\Domain\Fields;

use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Holds publishing and scheduling to the fields a type marks required; a
 * draft may be saved half-done (I-3343).
 */
final class RequiredFields
{
    /**
     * Returns when every required field of the type has a value in $fields;
     * throws a ValidationException naming each missing one under its field.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws ValidationException
     */
    public static function check(string $type, array $fields, string $noun): void
    {
        $problems = [];

        foreach (app(FieldRegistry::class)->forType($type) as $field) {
            $value = $fields[$field->key] ?? null;

            if ($field->required && ($value === null || $value === '' || $value === [])) {
                $problems["fields.{$field->key}"] = __('Fill in “:label” to publish this :noun.', ['label' => $field->label, 'noun' => $noun]);
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /**
     * The noun the message names: the type's singular in lower case, as the
     * editor names it.
     */
    public static function noun(string $type): string
    {
        $types = app(ContentTypeRegistry::class);

        return $types->has($type) ? mb_strtolower($types->get($type)->singular) : $type;
    }
}
