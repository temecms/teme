<?php

/**
 * A comment's restricted document (I-267) as HTML for the public site:
 * paragraphs, quotes, line breaks, bold, italic, code and links, every word
 * escaped. A link to another site is user-generated and unendorsed
 * (`rel="ugc nofollow"`); a link to an item here is stored by its ID and
 * given its address now (rule 9), or drawn as words when it has none. Only
 * http, https and mailto addresses are ever drawn as links.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\LinkResolver;

final class CommentBody
{
    /**
     * @param  array<mixed>  $document
     */
    public static function html(array $document, LinkResolver $links): string
    {
        return self::blocks(is_array($document['content'] ?? null) ? $document['content'] : [], $links);
    }

    /**
     * @param  array<mixed>  $blocks
     */
    private static function blocks(array $blocks, LinkResolver $links): string
    {
        $html = '';

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $inner = is_array($block['content'] ?? null) ? $block['content'] : [];

            $html .= ($block['type'] ?? null) === 'blockquote'
                ? '<blockquote>'.self::blocks($inner, $links).'</blockquote>'
                : '<p>'.self::inline($inner, $links).'</p>';
        }

        return $html;
    }

    /**
     * @param  array<mixed>  $children
     */
    private static function inline(array $children, LinkResolver $links): string
    {
        $html = '';

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            if (($child['type'] ?? null) === 'hardBreak') {
                $html .= '<br>';

                continue;
            }

            $text = e(is_string($child['text'] ?? null) ? $child['text'] : '');

            foreach (is_array($child['marks'] ?? null) ? $child['marks'] : [] as $mark) {
                $text = is_array($mark) ? self::marked($text, $mark, $links) : $text;
            }

            $html .= $text;
        }

        return $html;
    }

    /**
     * @param  array<mixed>  $mark
     */
    private static function marked(string $html, array $mark, LinkResolver $links): string
    {
        $href = is_array($mark['attrs'] ?? null) && is_string($mark['attrs']['href'] ?? null) ? $mark['attrs']['href'] : '';

        return match ($mark['type'] ?? null) {
            'bold' => '<strong>'.$html.'</strong>',
            'italic' => '<em>'.$html.'</em>',
            'code' => '<code>'.$html.'</code>',
            'link' => self::link($href, $html, $links),
            default => $html,
        };
    }

    private static function link(string $href, string $html, LinkResolver $links): string
    {
        if (preg_match(DocumentSchema::CONTENT_LINK, $href) === 1) {
            $url = $links->url(substr($href, strlen('teme:content/')));

            return $url === null ? $html : '<a href="'.e($url).'">'.$html.'</a>';
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto'], true)
            ? '<a href="'.e($href).'" rel="ugc nofollow">'.$html.'</a>'
            : $html;
    }
}
