<?php

/**
 * One image with its caption, credit and the alt text for this use. The
 * image is held by ID; its address and its sizes are worked out at render
 * time (rule 9). While an import brings its file, the card may hold the old
 * site's address instead, an outside picture, which the import swaps for
 * the library item once the file is here.
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
use App\Domain\Media\OutsideImage;
use Illuminate\Validation\ValidationException;

final class ImageCard implements CardType
{
    public function name(): string
    {
        return 'image';
    }

    public function label(): string
    {
        return 'Image';
    }

    public function description(): string
    {
        return 'One image, with a caption, a credit, and alt text for this use.';
    }

    public function since(): string
    {
        return '0.1.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            // One of the two: the library item, or the old site's address while an import brings its file (#716).
            'mediaId' => new CardAttribute('mediaId', ['nullable', 'string', 'size:26']),
            'source' => new CardAttribute('source', ['nullable', 'string', 'max:2048', 'url:http,https']),
            'sourceWidth' => new CardAttribute('sourceWidth', ['nullable', 'integer', 'min:1', 'max:100000']),
            'sourceHeight' => new CardAttribute('sourceHeight', ['nullable', 'integer', 'min:1', 'max:100000']),
            'alt' => new CardAttribute('alt', ['nullable', 'string', 'max:1000']),
            'decorative' => new CardAttribute('decorative', ['boolean'], false),
            'caption' => new CardAttribute('caption', ['nullable', 'string', 'max:2000']),
            'credit' => new CardAttribute('credit', ['nullable', 'string', 'max:255']),
            'width' => new CardAttribute('width', ['in:column,wide,full'], 'column'),
        ];
    }

    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Image', 'Cards', ['image', 'photo', 'picture', 'figure'], 'image');
    }

    public function renderHtml(array $attrs, RenderContext $context): string
    {
        $item = $context->media[(string) ($attrs['mediaId'] ?? '')] ?? null;
        $source = self::source($attrs);

        if ($item === null && $source === null) {
            return '';
        }

        // Drawn as every image on the site is, in the body's context, and to
        // load first only when it is the page's lead image (I-245).
        $lead = $context->leadNodeId !== null && ($attrs['id'] ?? null) === $context->leadNodeId;
        $image = $item !== null
            ? app(ImageMarkup::class)->item($item, 'content', $lead, (string) ($attrs['alt'] ?? ''), (bool) ($attrs['decorative'] ?? false))
            : self::outside((string) $source, $attrs);

        if ($image === '') {
            return '';
        }

        $classes = 'card-image card-image--'.($attrs['width'] ?? 'column');

        $caption = trim((string) ($attrs['caption'] ?? ''));
        $credit = trim((string) ($attrs['credit'] ?? ''));
        $figcaption = $caption === '' && $credit === '' ? '' : sprintf(
            '<figcaption>%s%s</figcaption>',
            $caption === '' ? '' : e($caption),
            $credit === '' ? '' : '<span class="credit">'.e($credit).'</span>',
        );

        return sprintf('<figure class="%s">%s%s</figure>', e($classes), $image, $figcaption);
    }

    public function plainText(array $attrs): string
    {
        return trim(implode(' ', array_filter([$attrs['caption'] ?? '', $attrs['alt'] ?? ''])));
    }

    public function references(array $attrs): array
    {
        return isset($attrs['mediaId']) ? [CardReference::media((string) $attrs['mediaId'])] : [];
    }

    /**
     * A card holds its image one way: from the library, or, while an import
     * brings the file, at the old site's address.
     */
    public function validate(array $attrs): void
    {
        $held = ($attrs['mediaId'] ?? null) !== null;
        $outside = self::source($attrs) !== null;

        if ($held === $outside) {
            throw ValidationException::withMessages(['mediaId' => $held
                ? 'An image comes from the library or from the old site, not both.'
                : 'Choose an image from the library.']);
        }
    }

    /**
     * The old site's address an outside picture is drawn from, or null for
     * a card that holds a library item.
     *
     * @param  array<string, mixed>  $attrs
     */
    public static function source(array $attrs): ?string
    {
        $source = $attrs['source'] ?? null;

        return is_string($source) && $source !== '' ? $source : null;
    }

    /**
     * An outside picture: the old site's file at its address, with its alt
     * text and size, until the import swaps in the library item. It sends no
     * referrer, which spares a site that refuses pictures shown elsewhere.
     *
     * @param  array<string, mixed>  $attrs
     */
    private static function outside(string $source, array $attrs): string
    {
        $alt = ($attrs['decorative'] ?? false) ? '' : trim((string) ($attrs['alt'] ?? ''));

        return OutsideImage::markup($source, $alt, is_int($attrs['sourceWidth'] ?? null) ? $attrs['sourceWidth'] : null, is_int($attrs['sourceHeight'] ?? null) ? $attrs['sourceHeight'] : null);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $parts = [];

        if (ChangeList::differs($before, $after, 'mediaId') || ChangeList::differs($before, $after, 'source')) {
            $parts[] = 'image replaced';
        }

        if (ChangeList::differs($before, $after, 'alt')) {
            $parts[] = 'alt text changed';
        }

        if ((bool) ($before['decorative'] ?? false) !== (bool) ($after['decorative'] ?? false)) {
            $parts[] = ($after['decorative'] ?? false) ? 'marked decorative' : 'no longer decorative';
        }

        foreach (['caption' => 'caption changed', 'credit' => 'credit changed', 'width' => 'width changed'] as $key => $words) {
            if (ChangeList::differs($before, $after, $key)) {
                $parts[] = $words;
            }
        }

        return ChangeList::line($this->label(), $parts);
    }
}
