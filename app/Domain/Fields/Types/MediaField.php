<?php

/**
 * A reference to a media item, stored as its ID — never a path or URL (rule
 * 9) — with the alt text and decorative mark for this one use, which are
 * versioned with the content (docs/architecture/media.md, "Alt text").
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Types;

use App\Domain\Cards\CardReference;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldType;
use App\Domain\Fields\Rules\MediaReference;
use App\Domain\Site\ViewModels\ImageRef;
use App\Domain\StructuredData\ImageObjects;

final class MediaField implements FieldType
{
    /**
     * @param  list<string>  $kinds  the kinds of media this field accepts
     * @param  bool  $allowsDecorative  whether a use may be marked decorative (I-237)
     */
    public function __construct(public readonly array $kinds = ['image'], public readonly bool $allowsDecorative = true) {}

    public function name(): string
    {
        return 'media';
    }

    public function rules(Field $field): array
    {
        return ['nullable', new MediaReference($this->kinds, $this->allowsDecorative)];
    }

    public function default(Field $field): mixed
    {
        return null;
    }

    /**
     * @return array{id: string, alt: string|null, decorative: bool}|null
     */
    public function normalise(Field $field, mixed $value): ?array
    {
        if (! is_array($value) || ($value['id'] ?? '') === '' || ($value['id'] ?? null) === null) {
            return null;
        }

        $alt = is_string($value['alt'] ?? null) ? trim($value['alt']) : '';

        return [
            'id' => (string) $value['id'],
            'alt' => $alt === '' ? null : $alt,
            'decorative' => (bool) filter_var($value['decorative'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * The image as an ImageObject.
     *
     * @return array<string, mixed>|null
     */
    public function structuredData(Field $field, mixed $value): ?array
    {
        $id = is_array($value) && is_string($value['id'] ?? null) && $value['id'] !== '' ? $value['id'] : null;

        return $id === null ? null : (app(ImageObjects::class)->for($id)['object'] ?? null);
    }

    /** The control, with the kinds of media it takes and whether a use may be decorative. */
    public function edit(Field $field): array
    {
        return ['component' => 'media', 'settings' => ['kinds' => $this->kinds, 'allowsDecorative' => $this->allowsDecorative]];
    }

    /** The image as a template draws it, or null when nothing is stored. */
    public function render(Field $field, mixed $value): ?ImageRef
    {
        $id = $this->idOf($value);

        if ($id === null) {
            return null;
        }

        return new ImageRef($id, is_string($value['alt'] ?? null) ? $value['alt'] : null, (bool) ($value['decorative'] ?? false));
    }

    /** Not sortable. */
    public function sqlType(Field $field): ?string
    {
        return null;
    }

    /** The media item, by ID. */
    public function references(Field $field, mixed $value): array
    {
        $id = $this->idOf($value);

        return $id === null ? [] : [CardReference::media($id)];
    }

    /** Nothing: an image adds no words to search. */
    public function text(Field $field, mixed $value): string
    {
        return '';
    }

    /** The media ID in a stored value, or null. */
    private function idOf(mixed $value): ?string
    {
        return is_array($value) && is_string($value['id'] ?? null) && $value['id'] !== '' ? $value['id'] : null;
    }
}
