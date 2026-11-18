<?php

/**
 * The button card in a feed: a paragraph holding a link, to the article's current address or to the address it was given.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\RenderContext;
use App\Domain\Feeds\FeedCardRenderer;

final readonly class PlainButton implements FeedCardRenderer
{
    public function card(): string
    {
        return 'button';
    }

    public function html(array $attrs, RenderContext $context): string
    {
        $contentId = $attrs['contentId'] ?? null;
        $href = is_string($contentId) && $contentId !== '' ? (string) $context->links->url($contentId) : (string) ($attrs['url'] ?? '');

        if (preg_match('~^https?://\S+$~i', $href) !== 1) {
            return '';
        }

        return '<p><a href="'.e($href).'">'.e((string) ($attrs['label'] ?? '')).'</a></p>';
    }
}
