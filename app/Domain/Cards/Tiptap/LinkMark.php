<?php

declare(strict_types=1);

namespace App\Domain\Cards\Tiptap;

use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\RenderContext;
use Tiptap\Marks\Link;

/**
 * The link mark as the site renders it. A link by ID (`teme:content/`,
 * `teme:term/`, `teme:user/` or `teme:media/` and an ID, I-1702) is drawn at
 * its target's address now (rule 9), keeping its place; when the target has
 * no address the text renders with no link at all. A place on the same page
 * and a path on this site are drawn as they are. None of these has a target
 * or a rel: `nofollow` on a site's own links tells search engines not to
 * follow them. An external link gets rel="noopener", and a target only when
 * the stored link asks for one.
 */
final class LinkMark extends Link
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(private readonly RenderContext $context, array $options = [])
    {
        parent::__construct($options);
    }

    /**
     * The anchor to draw, or null to draw none. tiptap-php calls this once for
     * the opening tag and again, without attributes, for the closing one, so
     * the answer depends on the mark alone.
     *
     * @param  object  $mark
     * @param  array<string, mixed>  $HTMLAttributes
     *
     * @return array{0: string, 1: array<string, string>, 2: int}|null
     */
    public function renderHTML($mark, $HTMLAttributes = []): ?array
    {
        $attrs = (array) ($mark->attrs ?? []);
        $href = is_string($attrs['href'] ?? null) ? $attrs['href'] : '';

        if (str_starts_with($href, 'teme:')) {
            $link = DocumentSchema::linkTarget($href);
            $url = $link === null ? null : $this->context->links->target($link['kind'], $link['id']);

            // A link by ID keeps its place (I-1670).
            return $link === null || $url === null ? null : ['a', ['href' => $url.$link['place']], 0];
        }

        // A place on the same page is drawn as it is, with no target and no rel (I-452).
        if (preg_match(DocumentSchema::FRAGMENT, $href) === 1) {
            return ['a', ['href' => $href], 0];
        }

        // A page of this site, with no target and no rel; absolute in a feed (I-1669).
        if (preg_match(DocumentSchema::SITE_PATH, $href) === 1) {
            return ['a', ['href' => $this->context->links->path($href)], 0];
        }

        if ($href === '' || ! DocumentSchema::allowsHref($href)) {
            return null;
        }

        $anchor = ['href' => $href];

        if (($attrs['target'] ?? null) === '_blank') {
            $anchor['target'] = '_blank';
        }

        $anchor['rel'] = 'noopener';

        return ['a', $anchor, 0];
    }
}
