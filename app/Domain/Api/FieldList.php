<?php

/**
 * The one rule for which of a type's own fields the API shows (I-3359, Q3 A):
 * its stored live fields shown on the site, after or before the article,
 * never a hidden one nor one of code's built-in fields; and each one's type
 * name as the API gives it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\FieldShow;
use App\Domain\Fields\Types\DatetimeField;

final class FieldList
{
    /**
     * The type's fields the API shows, in the type's order.
     *
     * @return list<Field>
     */
    public static function shown(string $type): array
    {
        return array_values(array_filter(app(FieldRegistry::class)->stored($type), fn (Field $field): bool => $field->show !== FieldShow::Hidden));
    }

    /**
     * The field's type name: `date` for a date-only date field, else its type's own name.
     */
    public static function typeName(Field $field): string
    {
        return $field->type instanceof DatetimeField && $field->type->dateOnly ? 'date' : $field->type->name();
    }
}
