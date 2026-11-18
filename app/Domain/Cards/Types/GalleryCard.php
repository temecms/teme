<?php

/**
 * Two to twenty-four images shown together, with one caption. The images are
 * held by ID in the order the writer set; any that have gone since are left
 * out when it renders.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Types;

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardReference;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\RenderContext;
use App\Domain\Media\ImageMarkup;
use Illuminate\Support\Facades\Validator;

final class GalleryCard implements CardType
{
    public function name(): string
    {
        return 'gallery';
    }

    public function label(): string
    {
        return 'Gallery';
    }

    public function description(): string
    {
        return 'Two to twenty-four images shown together, with one caption.';
    }

    public function since(): string
    {
        return '0.1.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            'mediaIds' => new CardAttribute('mediaIds', ['required', 'array', 'min:2', 'max:24']),
            'caption' => new CardAttribute('caption', ['nullable', 'string', 'max:2000']),
            'layout' => new CardAttribute('layout', ['in:grid,row'], 'grid'),
        ];
    }

    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Gallery', 'Cards', ['gallery', 'images', 'photos'], 'gallery');
    }

    public function renderHtml(array $attrs, RenderContext $context): string
    {
        $ids = array_values(array_map('strval', (array) ($attrs['mediaIds'] ?? [])));
        $images = '';

        foreach ($ids as $id) {
            $item = $context->media[$id] ?? null;

            if ($item === null) {
                continue;
            }

            // Drawn as every image on the site is, in the body's context (I-245).
            $image = app(ImageMarkup::class)->item($item, 'content');

            if ($image !== '') {
                $images .= '<li>'.$image.'</li>';
            }
        }

        if ($images === '') {
            return '';
        }

        $caption = trim((string) ($attrs['caption'] ?? ''));

        return sprintf(
            '<figure class="%s"><ul>%s</ul>%s</figure>',
            e('card-gallery card-gallery--'.($attrs['layout'] ?? 'grid')),
            $images,
            $caption === '' ? '' : '<figcaption>'.e($caption).'</figcaption>',
        );
    }

    public function plainText(array $attrs): string
    {
        return trim((string) ($attrs['caption'] ?? ''));
    }

    public function references(array $attrs): array
    {
        return array_values(array_map(
            fn (mixed $id): CardReference => CardReference::media((string) $id),
            (array) ($attrs['mediaIds'] ?? []),
        ));
    }

    /**
     * Each image ID has the shape of a media ID. The attribute's own rules
     * cover the list; this covers what is in it.
     */
    public function validate(array $attrs): void
    {
        Validator::make(
            ['mediaIds' => $attrs['mediaIds'] ?? []],
            ['mediaIds.*' => ['string', 'size:26']],
        )->validate();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $old = array_values(array_map('strval', (array) ($before['mediaIds'] ?? [])));
        $new = array_values(array_map('strval', (array) ($after['mediaIds'] ?? [])));
        $added = count(array_diff($new, $old));
        $removed = count(array_diff($old, $new));
        $parts = [];

        if ($added > 0) {
            $parts[] = $added === 1 ? '1 image added' : "{$added} images added";
        }

        if ($removed > 0) {
            $parts[] = $removed === 1 ? '1 image removed' : "{$removed} images removed";
        }

        if ($added === 0 && $removed === 0 && $old !== $new) {
            $parts[] = 'images reordered';
        }

        foreach (['caption' => 'caption changed', 'layout' => 'layout changed'] as $key => $words) {
            if (ChangeList::differs($before, $after, $key)) {
                $parts[] = $words;
            }
        }

        return ChangeList::line($this->label(), $parts);
    }
}
