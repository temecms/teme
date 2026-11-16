<?php

declare(strict_types=1);

namespace App\Domain\Cards;

use App\Domain\Site\Insertions\InsertionPlan;

/**
 * What a card needs while rendering: how to turn a content ID into an
 * address, which output is being produced, the media the document's cards
 * show, looked up once per document by CardMedia, where to record the cards
 * a page draws, which image card, if any, is the page's lead image, and the
 * insertion plan for a published article page (I-064).
 */
final readonly class RenderContext
{
    /**
     * @param  array<string, array{variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>, width: int, height: int, alt: string|null, title: string, focalX: int, focalY: int, kind: string, fileUrl: string|null, mimeType: string, uploadedAt: string}>  $media
     */
    public function __construct(
        public LinkResolver $links,
        public string $target = 'web',
        public array $media = [],
        /** The page's record of the cards drawn (I-248); null outside a public page. */
        public ?CardUsage $usage = null,
        /** The node ID of the image card that is the lead image, drawn to load first (I-245). */
        public ?string $leadNodeId = null,
        /** Insertions for a published web article; null outside that path (I-064). */
        public ?InsertionPlan $insertions = null,
    ) {}
}
