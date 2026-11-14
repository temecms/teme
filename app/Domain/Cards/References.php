<?php

declare(strict_types=1);

namespace App\Domain\Cards;

/**
 * What a document points at: the media its cards use, the articles its cards
 * lead to, and whatever its links lead to by ID, read from the stored JSON.
 * The projections are built from this, so they can always be rebuilt from
 * revisions.
 */
final class References
{
    /**
     * Every reference in the document, in document order, each once: the
     * media and articles its cards name, and what its links lead to by ID,
     * an article, a term, a person or a library file (I-1702).
     *
     * @param  array<mixed>  $document
     *
     * @return list<CardReference>
     */
    public static function of(array $document): array
    {
        $found = [];

        self::walk($document, app(CardRegistry::class), function (CardReference $reference, ?string $nodeId) use (&$found): void {
            $found["{$reference->kind}:{$reference->id}"] ??= $reference;
        });

        return array_values($found);
    }

    /**
     * The media the document uses, keyed as MediaProjection records each
     * use: `node:<node id>:<media id>` for a picture a card shows or names,
     * the media ID in the key so a gallery of two images is two entries, and
     * `link:<media id>` for a link to a library file, in the prose or in a
     * card, once however many links lead to it (I-1702).
     *
     * @param  array<mixed>  $document
     *
     * @return array<string, string>
     */
    public static function mediaUses(array $document): array
    {
        $found = [];

        self::walk($document, app(CardRegistry::class), function (CardReference $reference, ?string $nodeId) use (&$found): void {
            if ($reference->kind !== 'media') {
                return;
            }

            if ($reference->linked) {
                $found["link:{$reference->id}"] = $reference->id;
            } elseif ($nodeId !== null) {
                $found["node:{$nodeId}:{$reference->id}"] = $reference->id;
            }
        });

        return $found;
    }

    /**
     * What a link by ID leads to, as a linked reference; null for any other
     * address.
     */
    public static function link(string $href): ?CardReference
    {
        $target = DocumentSchema::linkTarget($href);

        return $target === null ? null : CardReference::linkTo($target['kind'], $target['id']);
    }

    /**
     * The IDs of the articles the document leads to, each once.
     *
     * @param  array<mixed>  $document
     *
     * @return list<string>
     */
    public static function contentIds(array $document): array
    {
        $ids = [];

        foreach (self::of($document) as $reference) {
            if ($reference->kind === 'content') {
                $ids[] = $reference->id;
            }
        }

        return $ids;
    }

    /**
     * Calls $found for each reference, with the card node's id when it came
     * from a card.
     *
     * @param  array<mixed>  $node
     * @param  callable(CardReference, ?string): void  $found
     */
    private static function walk(array $node, CardRegistry $cards, callable $found): void
    {
        $card = $cards->find((string) ($node['type'] ?? ''));

        if ($card !== null) {
            $attrs = (array) ($node['attrs'] ?? []);
            $nodeId = is_string($attrs['id'] ?? null) ? $attrs['id'] : null;

            foreach ($card->references($attrs) as $reference) {
                $found($reference, $nodeId);
            }

            return;
        }

        foreach ((array) ($node['marks'] ?? []) as $mark) {
            $href = is_array($mark) && ($mark['type'] ?? null) === 'link' ? ($mark['attrs']['href'] ?? null) : null;
            $reference = is_string($href) ? self::link($href) : null;

            if ($reference !== null) {
                $found($reference, null);
            }
        }

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                self::walk($child, $cards, $found);
            }
        }
    }
}
