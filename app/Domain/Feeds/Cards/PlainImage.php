<?php

/**
 * The image card in a feed: the picture at an absolute address with its caption and credit, and none of the page's styling.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\RenderContext;
use App\Domain\Cards\Types\ImageCard;
use App\Domain\Feeds\FeedCardRenderer;
use App\Domain\Feeds\FeedImages;

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
        $source = ImageCard::source($attrs);
        $image = match (true) {
            $item !== null => $this->images->img($item, (string) ($attrs['alt'] ?? ''), (bool) ($attrs['decorative'] ?? false)),
            // An outside picture, while an import copies its file: the old site's address (#716).
            $source !== null => sprintf('<img src="%s" alt="%s">', e($source), e(($attrs['decorative'] ?? false) ? '' : trim((string) ($attrs['alt'] ?? '')))),
            default => '',
        };

        if ($image === '') {
            return '';
        }

        $caption = trim((string) ($attrs['caption'] ?? ''));
        $credit = trim((string) ($attrs['credit'] ?? ''));
        $words = trim(e($caption).($credit === '' ? '' : ' <small>'.e($credit).'</small>'));

        return '<figure>'.$image.($words === '' ? '' : '<figcaption>'.$words.'</figcaption>').'</figure>';
    }
}
