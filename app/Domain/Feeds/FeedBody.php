<?php

/**
 * An item's body as HTML for a feed reader (I-861): runs of prose drawn by
 * the page's own renderer, every card by its plain version, links and
 * pictures at absolute addresses. Nothing is rewritten afterwards.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds;

use App\Domain\Cards\CardMedia;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\CardType;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\RenderContext;
use App\Domain\Site\Links\ArticleLinkAttributes;

final readonly class FeedBody
{
    public function __construct(
        private DocumentRenderer $renderer,
        private CardRegistry $cards,
        private CardMedia $media,
        private FeedCards $feedCards,
        private LinkResolver $links,
        private FeedUrls $urls,
        private ArticleLinkAttributes $linkAttributes,
    ) {}

    /**
     * @param  array<mixed>  $document
     */
    public function html(array $document): string
    {
        // The media of every card, in one statement; the context a prose run is drawn with needs none.
        $links = new AbsoluteLinks($this->links, $this->urls);
        // The same attributes plugins ask for on the article's page (docs/plans/plugin-sockets-3.md).
        $context = new RenderContext($links, 'feed', $this->media->forDocument($document), linkAttributes: $this->linkAttributes->for($document, $links));
        $html = '';
        $run = [];

        foreach ((array) ($document['content'] ?? []) as $node) {
            $card = is_array($node) ? $this->cards->find((string) ($node['type'] ?? '')) : null;

            if ($card === null) {
                $run[] = $node;

                continue;
            }

            $html .= $this->prose($run, $context);
            $run = [];
            $html .= $this->feedCards->html($card, $this->withDefaults($card, (array) ($node['attrs'] ?? [])), $context);
        }

        return $html.$this->prose($run, $context);
    }

    /**
     * @param  list<mixed>  $run
     */
    private function prose(array $run, RenderContext $context): string
    {
        return $run === [] ? '' : $this->renderer->html(['type' => 'doc', 'content' => $run], $context);
    }

    /**
     * The card's attributes with the declared default for any it left out, as the page's renderer gives them.
     *
     * @param  array<mixed>  $attrs
     *
     * @return array<string, mixed>
     */
    private function withDefaults(CardType $card, array $attrs): array
    {
        foreach ($card->attributes() as $name => $attribute) {
            if (! array_key_exists($name, $attrs) && $attribute->default !== null) {
                $attrs[$name] = $attribute->default;
            }
        }

        return $attrs;
    }
}
