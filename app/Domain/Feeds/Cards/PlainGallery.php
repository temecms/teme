<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\RenderContext;
use App\Domain\Feeds\FeedCardRenderer;
use App\Domain\Feeds\FeedImages;

/**
 * The gallery card in a feed: its pictures one to a paragraph, in the writer's order, with the gallery's caption.
 */
final readonly class PlainGallery implements FeedCardRenderer
{
    public function __construct(private FeedImages $images) {}

    public function card(): string
    {
        return 'gallery';
    }

    public function html(array $attrs, RenderContext $context): string
    {
        $images = '';

        foreach ((array) ($attrs['mediaIds'] ?? []) as $id) {
            $item = $context->media[(string) $id] ?? null;
            $image = $item === null ? '' : $this->images->img($item, null);

            if ($image !== '') {
                $images .= '<p>'.$image.'</p>';
            }
        }

        if ($images === '') {
            return '';
        }

        $caption = trim((string) ($attrs['caption'] ?? ''));

        return '<figure>'.$images.($caption === '' ? '' : '<figcaption>'.e($caption).'</figcaption>').'</figure>';
    }
}
