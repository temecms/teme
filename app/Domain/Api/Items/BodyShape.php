<?php

declare(strict_types=1);

namespace App\Domain\Api\Items;

use App\Domain\Api\DocumentLinks;
use App\Domain\Api\Shapes\MediaShape;
use App\Domain\Cards\CardMedia;
use App\Domain\Cards\LinkResolver;
use App\Domain\Feeds\FeedBody;
use App\Domain\Feeds\FeedUrls;
use stdClass;

/**
 * An item's body as the API gives it (D7, rules 7 and 9): the feed's HTML,
 * the Tiptap document with every link resolved now, and the files its
 * cards show. What a reader can't reach is left out, as the page leaves it.
 * Both forms draw their links from one BodyLinks, which reads every target
 * of the document in one statement.
 */
final readonly class BodyShape
{
    /**
     * Attributes the page never prints, by node type, left out of `json`:
     * the embed card's `html` is the provider's own markup, and its
     * `provider` a name the page never shows (EmbedCard).
     *
     * @var array<string, list<string>>
     */
    public const array DROPPED = ['embed' => ['html', 'provider']];

    public function __construct(private LinkResolver $site, private FeedUrls $urls, private CardMedia $media) {}

    /**
     * The body's three members: `html`, `json` and `media`, the files its
     * cards show keyed by ID (`{}` for none).
     *
     * @param  array<mixed>  $document  the published revision's document
     *
     * @return array{html: string, json: array<mixed>, media: array<string, array<string, mixed>>|stdClass}
     */
    public function of(array $document): array
    {
        $media = [];

        // First, so a link to a library file finds its file already read.
        foreach ($this->media->forDocument($document) as $id => $file) {
            $media[(string) $id] = MediaShape::of(['id' => (string) $id, ...$file]);
        }

        $links = BodyLinks::for($document, $this->site);

        return [
            'html' => app()->make(FeedBody::class, ['links' => $links])->html($document),
            'json' => $this->json($document, new DocumentLinks($links, $this->urls)),
            'media' => $media === [] ? new stdClass : $media,
        ];
    }

    /**
     * The document with every link resolved now (rule 9, D7): a link by ID or
     * a path on this site at its absolute address, or gone when its target has
     * none; a card's article as its address, and a card whose article has
     * none left out whole. Media IDs stay; the body's media map resolves them.
     *
     * @param  array<mixed>  $node
     *
     * @return array<mixed>
     */
    private function json(array $node, DocumentLinks $links): array
    {
        if (is_array($node['marks'] ?? null)) {
            $node['marks'] = array_values(array_filter(
                array_map(fn (mixed $mark): mixed => $this->mark($mark, $links), $node['marks']),
                fn (mixed $mark): bool => $mark !== null,
            ));

            if ($node['marks'] === []) {
                unset($node['marks']);
            }
        }

        if (is_array($node['attrs'] ?? null)) {
            foreach (self::DROPPED[is_string($node['type'] ?? null) ? $node['type'] : ''] ?? [] as $dropped) {
                unset($node['attrs'][$dropped]);
            }

            $node['attrs'] = $this->attrs($node['attrs'], $links);
        }

        if (is_array($node['content'] ?? null)) {
            $node['content'] = array_values(array_map(
                fn (mixed $child): mixed => is_array($child) ? $this->json($child, $links) : $child,
                array_filter($node['content'], fn (mixed $child): bool => ! is_array($child) || $this->reachable($child, $links)),
            ));
        }

        return $node;
    }

    /**
     * Whether a node's article, when it names one, has an address now: a
     * button whose article has none is drawn as nothing (ButtonCard), so
     * its label never goes out.
     *
     * @param  array<mixed>  $node
     */
    private function reachable(array $node, DocumentLinks $links): bool
    {
        $id = is_array($node['attrs'] ?? null) ? ($node['attrs']['contentId'] ?? null) : null;

        return ! is_string($id) || $id === '' || $links->url($id) !== null;
    }

    private function mark(mixed $mark, DocumentLinks $links): mixed
    {
        if (! is_array($mark) || ($mark['type'] ?? null) !== 'link') {
            return $mark;
        }

        $url = $links->href(is_string($mark['attrs']['href'] ?? null) ? $mark['attrs']['href'] : '');

        if ($url === null) {
            return null;
        }

        $mark['attrs']['href'] = $url;

        return $mark;
    }

    /**
     * A card's or a node's attributes, at any depth: `contentId` becomes the
     * article's address as `href`, or goes; an `href` that is a link by ID is
     * resolved, or goes.
     *
     * @param  array<mixed>  $attrs
     *
     * @return array<mixed>
     */
    private function attrs(array $attrs, DocumentLinks $links): array
    {
        if (array_key_exists('contentId', $attrs)) {
            $id = $attrs['contentId'];
            unset($attrs['contentId']);
            $url = is_string($id) && $id !== '' ? $links->url($id) : null;

            if ($url !== null) {
                $attrs['href'] = $url;
            }
        }

        foreach ($attrs as $key => $value) {
            if (is_array($value)) {
                $attrs[$key] = $this->attrs($value, $links);
            } elseif ($key === 'href' && is_string($value) && str_starts_with($value, 'teme:')) {
                $url = $links->href($value);

                if ($url === null) {
                    unset($attrs[$key]);
                } else {
                    $attrs[$key] = $url;
                }
            }
        }

        return $attrs;
    }
}
