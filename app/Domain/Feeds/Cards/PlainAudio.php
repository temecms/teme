<?php

/**
 * The audio card in a feed: the browser's own player at an absolute address, loading nothing until pressed, a link to the file inside it for a reader without one, and the caption.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\RenderContext;
use App\Domain\Feeds\FeedCardRenderer;
use App\Domain\Feeds\FeedUrls;

final readonly class PlainAudio implements FeedCardRenderer
{
    public function __construct(private FeedUrls $urls) {}

    public function card(): string
    {
        return 'audio';
    }

    public function html(array $attrs, RenderContext $context): string
    {
        $file = $context->media[(string) ($attrs['mediaId'] ?? '')] ?? null;

        if ($file === null || $file['kind'] !== 'audio' || $file['fileUrl'] === null) {
            return '';
        }

        $url = e($this->urls->absolute($file['fileUrl']));
        $caption = trim((string) ($attrs['caption'] ?? ''));

        return '<figure><audio src="'.$url.'" controls preload="none"><a href="'.$url.'">'.e($file['title']).'</a></audio>'
            .($caption === '' ? '' : '<figcaption>'.e($caption).'</figcaption>').'</figure>';
    }
}
