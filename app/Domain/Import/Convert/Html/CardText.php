<?php

/**
 * Converted blocks as the rich text a card holds (CardProse): paragraphs
 * and lists stay, a heading becomes a paragraph, and anything a card's text
 * can't hold, such as an image, a table or a card, means it can't be held
 * at all, so the caller keeps the blocks as the body's own instead and
 * nothing is lost.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Html;

use App\Domain\Cards\CardProse;

final class CardText
{
    /**
     * The blocks as one piece of card prose, or null when one of them
     * can't be held.
     *
     * @param  list<array<string, mixed>>  $blocks
     *
     * @return array{type: string, content: list<array<string, mixed>>}|null
     */
    public static function of(array $blocks): ?array
    {
        $content = self::blocks($blocks);

        if ($content === null) {
            return null;
        }

        return ['type' => 'doc', 'content' => $content === [] ? [Nodes::paragraph()] : $content];
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     *
     * @return list<array<string, mixed>>|null
     */
    private static function blocks(array $nodes): ?array
    {
        $kept = [];

        foreach ($nodes as $node) {
            $type = is_array($node) ? ($node['type'] ?? null) : null;

            if ($type === 'heading') {
                $node = ['type' => 'paragraph', 'content' => $node['content'] ?? []];
                $type = 'paragraph';
            }

            if (! is_string($type) || ! in_array($type, CardProse::NODES, true)) {
                return null;
            }

            unset($node['attrs']['id']);

            if (($node['attrs'] ?? null) === []) {
                unset($node['attrs']);
            }

            if (is_array($node['content'] ?? null)) {
                $inner = self::blocks($node['content']);

                if ($inner === null) {
                    return null;
                }

                $node['content'] = $inner;

                if ($inner === []) {
                    unset($node['content']);
                }
            }

            $kept[] = $node;
        }

        return $kept;
    }
}
