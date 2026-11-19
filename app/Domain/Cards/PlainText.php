<?php

/**
 * A document's words, for search and the word count. Text nodes give their
 * text, a card gives its own plainText(), and a code block gives nothing:
 * code is not prose, and indexing it drowns the search index. Each block
 * boundary is one space.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

final class PlainText
{
    /**
     * The document's text on one line.
     *
     * @param  array<mixed>  $document
     */
    public static function of(array $document): string
    {
        $text = self::walk($document, app(CardRegistry::class));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * How many words the document holds.
     *
     * @param  array<mixed>  $document
     */
    public static function words(array $document): int
    {
        $text = self::of($document);

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function walk(array $node, CardRegistry $cards): string
    {
        $type = (string) ($node['type'] ?? '');

        if ($type === 'text') {
            return (string) ($node['text'] ?? '');
        }

        if ($type === 'codeBlock') {
            return ' ';
        }

        if ($type === 'hardBreak') {
            return ' ';
        }

        $card = $cards->find($type);

        if ($card !== null) {
            return ' '.$card->plainText((array) ($node['attrs'] ?? [])).' ';
        }

        $text = '';

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                $text .= self::walk($child, $cards);
            }
        }

        return $text.' ';
    }
}
