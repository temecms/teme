<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\RenderContext;
use App\Domain\Feeds\FeedCardRenderer;
use App\Domain\Feeds\FeedImages;

/**
 * The image card in a feed: the picture at an absolute address with its caption and credit, and none of the page's styling.
 */
final readonly class PlainImage implements FeedCardRenderer
{
    public function __construct(private FeedImages $images) {}

    public function card(): string
    {
        return 'image';
    }

    public function html(array $attrs, RenderContext $context): string
    {
        $item = $context->media[(string) ($attrs['mediaId'] ?? '')] ?? null;
        $image = $item === null ? '' : $this->images->img($item, (string) ($attrs['alt'] ?? ''), (bool) ($attrs['decorative'] ?? false));

        if ($image === '') {
            return '';
        }

        $caption = trim((string) ($attrs['caption'] ?? ''));
        $credit = trim((string) ($attrs['credit'] ?? ''));
        $words = trim(e($caption).($credit === '' ? '' : ' <small>'.e($credit).'</small>'));

        return '<figure>'.$image.($words === '' ? '' : '<figcaption>'.$words.'</figcaption>').'</figure>';
    }
}
