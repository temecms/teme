<?php

/**
 * Which page templates an item may choose (I-443): those the active theme
 * declares for its type. Only a new choice is checked. A template already
 * stored stays, even once a later theme lacks it, so saving other edits
 * never fails over it; the resolver passes over it to the next template down.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

use App\Domain\Fields\BuiltInFields;
use Illuminate\Validation\ValidationException;

final class TemplateChoice
{
    /**
     * The active theme's templates for this type, slug => label in the
     * manifest's order, without `default`, which is the empty choice. None
     * when no theme is usable.
     *
     * @return array<string, string>
     */
    public static function declared(string $type): array
    {
        $manifest = app(ActiveTheme::class)->get()?->manifest;
        $declared = $manifest?->templates()[$type] ?? [];

        unset($declared['default']);

        return $declared;
    }

    /**
     * Refuses a template chosen now that the active theme doesn't offer for
     * the type. Runs after FieldValidator, so the value is a string or null.
     *
     * @param  array<string, mixed>  $fields  the fields being saved
     * @param  array<string, mixed>  $stored  the fields of the revision they replace
     */
    public static function check(string $type, array $fields, array $stored): void
    {
        $refused = self::refused($type, $fields, $stored);

        if ($refused !== null) {
            throw ValidationException::withMessages([
                'fields.'.BuiltInFields::TEMPLATE => __('The template ":slug" isn\'t one the active theme offers for this type.', ['slug' => $refused]),
            ]);
        }
    }

    /**
     * The fields, with a template check() would refuse replaced by the stored one.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $stored
     *
     * @return array<string, mixed>
     */
    public static function kept(string $type, array $fields, array $stored): array
    {
        if (self::refused($type, $fields, $stored) !== null) {
            $fields[BuiltInFields::TEMPLATE] = $stored[BuiltInFields::TEMPLATE] ?? '';
        }

        return $fields;
    }

    /**
     * The newly chosen template the theme doesn't offer, or null when the choice is empty, unchanged or offered.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $stored
     */
    private static function refused(string $type, array $fields, array $stored): ?string
    {
        $chosen = self::value($fields);

        if ($chosen === '' || $chosen === self::value($stored) || array_key_exists($chosen, self::declared($type))) {
            return null;
        }

        return $chosen;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function value(array $fields): string
    {
        $value = $fields[BuiltInFields::TEMPLATE] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
