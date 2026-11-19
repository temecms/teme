<?php

/**
 * One image with its caption, credit and the alt text for this use. The
 * image is held by ID; its address and its sizes are worked out at render
 * time (rule 9).
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
            'mediaId' => new CardAttribute('mediaId', ['required', 'string', 'size:26']),
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

        if ($item === null) {
            return '';
        }

        // Drawn as every image on the site is, in the body's context, and to
        // load first only when it is the page's lead image (I-245).
        $lead = $context->leadNodeId !== null && ($attrs['id'] ?? null) === $context->leadNodeId;
        $image = app(ImageMarkup::class)->item($item, 'content', $lead, (string) ($attrs['alt'] ?? ''), (bool) ($attrs['decorative'] ?? false));

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

    public function validate(array $attrs): void {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $parts = [];

        if (ChangeList::differs($before, $after, 'mediaId')) {
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
