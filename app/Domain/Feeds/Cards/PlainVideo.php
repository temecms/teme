<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\RenderContext;
use App\Domain\Feeds\FeedCardRenderer;
use App\Domain\Feeds\FeedImages;
use App\Domain\Feeds\FeedUrls;

/**
 * The video card in a feed: the browser's own player at an absolute address with its poster and size, always with controls and never playing by itself, a link to the file inside it, and the caption. Caption tracks stay on the page: a reader's page can't load them from another site.
 */
final readonly class PlainVideo implements FeedCardRenderer
{
    public function __construct(private FeedUrls $urls, private FeedImages $images) {}

    public function card(): string
    {
        return 'video';
    }

    public function html(array $attrs, RenderContext $context): string
    {
        $file = $context->media[(string) ($attrs['mediaId'] ?? '')] ?? null;

        if ($file === null || $file['kind'] !== 'video' || $file['fileUrl'] === null) {
            return '';
        }

        $posterItem = $context->media[(string) ($attrs['posterMediaId'] ?? '')] ?? null;
        $poster = $posterItem === null ? null : $this->images->variant($posterItem, FeedImages::BODY_WIDTH);
        $width = $attrs['videoWidth'] ?? null;
        $height = $attrs['videoHeight'] ?? null;
        $sized = is_int($width) && is_int($height) && $width >= 1 && $height >= 1;
        $url = e($this->urls->absolute($file['fileUrl']));
        $caption = trim((string) ($attrs['caption'] ?? ''));

        return '<figure><video src="'.$url.'" controls preload="none"'
            .($poster === null ? '' : ' poster="'.e($poster['url']).'"')
            .($sized ? ' width="'.$width.'" height="'.$height.'"' : '')
            .'><a href="'.$url.'">'.e($file['title']).'</a></video>'
            .($caption === '' ? '' : '<figcaption>'.e($caption).'</figcaption>').'</figure>';
    }
}
