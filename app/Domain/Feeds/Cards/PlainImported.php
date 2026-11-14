<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Cards;

use App\Domain\Cards\Imported\MarkupTree;
use App\Domain\Cards\RenderContext;
use App\Domain\Feeds\FeedCardRenderer;
use App\Domain\Feeds\FeedImages;

/**
 * The Imported content card in a feed: its listed elements as they are, with no wrapper and no class, links and pictures at absolute addresses.
 */
final readonly class PlainImported implements FeedCardRenderer
{
    public function __construct(private FeedImages $images) {}

    public function card(): string
    {
        return 'imported';
    }

    public function html(array $attrs, RenderContext $context): string
    {
        return MarkupTree::html(
            $attrs['markup'] ?? [],
            $context,
            fn (string $mediaId, string $alt): string => isset($context->media[$mediaId]) ? $this->images->img($context->media[$mediaId], $alt) : '',
        );
    }
}
