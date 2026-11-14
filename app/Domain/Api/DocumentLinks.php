<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\LinkResolver;
use App\Domain\Feeds\AbsoluteLinks;
use App\Domain\Feeds\FeedUrls;

/**
 * The addresses a document's links are drawn with now, absolute (rule 9):
 * a link by ID at its target's address with its place kept, a path on this
 * site made absolute, any other allowed address as stored; null when the
 * target has none or the address isn't allowed, as LinkMark then draws no link.
 */
final readonly class DocumentLinks
{
    private LinkResolver $links;

    public function __construct(LinkResolver $links, FeedUrls $urls)
    {
        $this->links = new AbsoluteLinks($links, $urls);
    }

    /**
     * The address a stored href is drawn with now, absolute, or null for none.
     */
    public function href(string $href): ?string
    {
        if (str_starts_with($href, 'teme:')) {
            $target = DocumentSchema::linkTarget($href);
            $url = $target === null ? null : $this->links->target($target['kind'], $target['id']);

            return $url === null ? null : $url.$target['place'];
        }

        if (preg_match(DocumentSchema::SITE_PATH, $href) === 1) {
            return $this->links->path($href);
        }

        // As LinkMark: an address it wouldn't draw, even one written past the editor, is no link.
        return $href === '' || ! DocumentSchema::allowsHref($href) ? null : $href;
    }

    /**
     * An item's address now, absolute, or null while it has none.
     */
    public function url(string $contentId): ?string
    {
        return $this->links->url($contentId);
    }
}
