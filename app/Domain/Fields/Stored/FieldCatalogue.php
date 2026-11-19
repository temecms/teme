<?php

/**
 * The nine field types a site may give its own fields (I-3320, I-3323):
 * each one's name in the builder, its line, its icon, the settings it takes,
 * which boxes it offers, and how its field type is built from a definition.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Stored;

use App\Domain\Fields\FieldType;
use App\Domain\Fields\Types\BooleanField;
use App\Domain\Fields\Types\DatetimeField;
use App\Domain\Fields\Types\EntryField;
use App\Domain\Fields\Types\MediaField;
use App\Domain\Fields\Types\NumberField;
use App\Domain\Fields\Types\SelectField;
use App\Domain\Fields\Types\TextareaField;
use App\Domain\Fields\Types\TextField;
use App\Domain\Fields\Types\UrlField;
use InvalidArgumentException;

final class FieldCatalogue
{
    /**
     * In the builder's order.
     *
     * @var array<string, array{label: string, line: string, icon: string, settings: list<string>, search: bool, filterable: bool, required: bool}>
     */
    private const array TYPES = [
        'text' => ['label' => 'Text', 'line' => 'One line, such as a tagline.', 'icon' => 'type', 'settings' => [], 'search' => true, 'filterable' => true, 'required' => true],
        'textarea' => ['label' => 'Long text', 'line' => 'A few plain lines, such as a short note.', 'icon' => 'align-left', 'settings' => [], 'search' => true, 'filterable' => false, 'required' => true],
        'number' => ['label' => 'Number', 'line' => 'Such as a runtime, in minutes.', 'icon' => 'hash', 'settings' => [], 'search' => false, 'filterable' => true, 'required' => true],
        'select' => ['label' => 'Choice', 'line' => 'One from a list you set, such as a rating.', 'icon' => 'list', 'settings' => ['choices'], 'search' => true, 'filterable' => true, 'required' => true],
        'boolean' => ['label' => 'Yes or no', 'line' => 'A tick box.', 'icon' => 'toggle-left', 'settings' => [], 'search' => false, 'filterable' => true, 'required' => false],
        'url' => ['label' => 'Web address', 'line' => 'An address on another site, such as a trailer.', 'icon' => 'globe', 'settings' => [], 'search' => false, 'filterable' => false, 'required' => true],
        'datetime' => ['label' => 'Date and time', 'line' => 'Or a date alone, such as a release date.', 'icon' => 'calendar', 'settings' => ['dateOnly'], 'search' => false, 'filterable' => true, 'required' => true],
        'media' => ['label' => 'Media', 'line' => 'An image or file from the library, such as a poster.', 'icon' => 'image', 'settings' => [], 'search' => false, 'filterable' => false, 'required' => true],
        'entry' => ['label' => 'One item', 'line' => 'Another item on this site, such as a sequel.', 'icon' => 'file', 'settings' => [], 'search' => false, 'filterable' => false, 'required' => true],
    ];

    /**
     * Whether the builder offers this field type.
     */
    public static function has(string $fieldType): bool
    {
        return isset(self::TYPES[$fieldType]);
    }

    /**
     * The field type a definition of this kind is drawn and stored by.
     *
     * @param  array<string, mixed>  $settings
     *
     * @throws InvalidArgumentException for a field type the builder doesn't offer
     */
    public static function make(string $fieldType, array $settings): FieldType
    {
        return match ($fieldType) {
            'text' => new TextField,
            'textarea' => new TextareaField,
            'number' => new NumberField,
            'select' => new SelectField,
            'boolean' => new BooleanField,
            'url' => new UrlField,
            'datetime' => new DatetimeField(dateOnly: ($settings['dateOnly'] ?? false) === true),
            'media' => new MediaField(['image'], allowsDecorative: true),
            'entry' => new EntryField,
            default => throw new InvalidArgumentException("[{$fieldType}] isn’t a field type Teme has."),
        };
    }

    /**
     * The field type's name in the builder, or the name itself for one it
     * doesn't offer.
     */
    public static function label(string $fieldType): string
    {
        return self::TYPES[$fieldType]['label'] ?? $fieldType;
    }

    /**
     * The settings this field type takes; none for one the builder doesn't offer.
     *
     * @return list<string>
     */
    public static function settings(string $fieldType): array
    {
        return self::TYPES[$fieldType]['settings'] ?? [];
    }

    /**
     * Whether this field type offers the box: search, filterable or required.
     */
    public static function offers(string $fieldType, string $box): bool
    {
        return (self::TYPES[$fieldType][$box] ?? false) === true;
    }

    /**
     * The builder's list of field types, in its order.
     *
     * @return list<array{name: string, label: string, line: string, icon: string, settings: list<string>, search: bool, filterable: bool, required: bool}>
     */
    public static function forBuilder(): array
    {
        $types = [];

        foreach (self::TYPES as $name => $type) {
            $types[] = ['name' => $name, ...$type];
        }

        return $types;
    }
}
