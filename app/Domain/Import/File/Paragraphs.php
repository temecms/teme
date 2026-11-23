<?php

/**
 * Makes paragraphs of classic-editor HTML as WordPress shows it: blank lines
 * separate paragraphs and single newlines become line breaks, while
 * block-level elements and shortcodes on their own line stay unwrapped.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

final class Paragraphs
{
    /**
     * Elements that are never wrapped in a paragraph.
     */
    private const string BLOCKS = 'address|area|article|aside|blockquote|caption|col|colgroup|dd|details|div|dl|dt|fieldset|figcaption|figure|footer|form|h[1-6]|header|hgroup|hr|legend|li|main|map|math|menu|nav|ol|option|p|pre|script|section|select|style|summary|table|tbody|td|textarea|tfoot|th|thead|tr|ul';

    /**
     * Elements whose contents are kept exactly as written.
     */
    private const array RAW = ['pre', 'script', 'style', 'textarea'];

    /**
     * Elements with no closing tag.
     */
    private const array VOID = ['area', 'col', 'hr'];

    /**
     * Elements whose own text is made into paragraphs: a blockquote always,
     * the others when their text holds a blank line.
     */
    private const array CONTAINERS = ['article', 'aside', 'blockquote', 'details', 'div', 'figure', 'footer', 'header', 'main', 'nav', 'section'];

    /**
     * The HTML with its paragraphs marked, each top-level piece on a line of
     * its own; an empty string when the HTML holds only whitespace.
     */
    public static function make(string $html): string
    {
        $pieces = self::pieces(str_replace(["\r\n", "\r"], "\n", $html));

        return $pieces === [] ? '' : implode("\n", $pieces)."\n";
    }

    /**
     * The top-level pieces in order: block-level elements as written (a
     * container's text made into paragraphs), and the text between them as
     * paragraphs or lone shortcodes.
     *
     * @return list<string>
     */
    private static function pieces(string $html): array
    {
        $pieces = [];
        $at = 0;

        while (preg_match('~<(/?)('.self::BLOCKS.')\b[^>]*+>~i', $html, $match, PREG_OFFSET_CAPTURE, $at) === 1) {
            [$tag, $offset] = $match[0];
            array_push($pieces, ...self::paragraphs(substr($html, $at, $offset - $at)));

            $name = strtolower($match[2][0]);
            $innerStart = $offset + strlen($tag);

            if ($match[1][0] === '/' || in_array($name, self::VOID, true) || str_ends_with($tag, '/>')) {
                $pieces[] = $tag;
                $at = $innerStart;

                continue;
            }

            [$innerEnd, $end] = self::closing($html, $name, $innerStart);
            $close = substr($html, $innerEnd, $end - $innerEnd);
            $pieces[] = $tag.self::inner($name, substr($html, $innerStart, $innerEnd - $innerStart)).$close;
            $at = $end;
        }

        array_push($pieces, ...self::paragraphs(substr($html, $at)));

        return $pieces;
    }

    /**
     * Where the element's contents end and where its closing tag ends: the
     * matching closing tag, counting nested elements of the same name, or
     * the end of the HTML when it has none.
     *
     * @return array{int, int}
     */
    private static function closing(string $html, string $name, int $from): array
    {
        $raw = in_array($name, self::RAW, true);
        $depth = 0;
        $pattern = $raw ? '~</'.$name.'\s*>~i' : '~<(/?)'.$name.'\b[^>]*+>~i';

        while (preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE, $from) === 1) {
            [$tag, $offset] = $match[0];
            $from = $offset + strlen($tag);

            if ($raw || $match[1][0] === '/') {
                if ($depth === 0) {
                    return [$offset, $from];
                }

                $depth--;
            } elseif (! str_ends_with($tag, '/>')) {
                $depth++;
            }
        }

        return [strlen($html), strlen($html)];
    }

    /**
     * An element's contents: a container's text made into paragraphs,
     * everything else as written.
     */
    private static function inner(string $name, string $inner): string
    {
        $isContainer = in_array($name, self::CONTAINERS, true)
            && ($name === 'blockquote' || preg_match('~\n[ \t]*\n~', $inner) === 1);

        if (! $isContainer) {
            return $inner;
        }

        $pieces = self::pieces($inner);

        if ($pieces === []) {
            return $inner;
        }

        $before = preg_match('~^[ \t]*\n~', $inner) === 1 ? "\n" : '';
        $after = preg_match('~\n[ \t]*$~', $inner) === 1 ? "\n" : '';

        return $before.implode("\n", $pieces).$after;
    }

    /**
     * Text outside block-level elements: one paragraph per run of lines
     * between blank lines, its newlines made line breaks, and a shortcode
     * alone between blank lines left as it is.
     *
     * @return list<string>
     */
    private static function paragraphs(string $text): array
    {
        $paragraphs = [];

        foreach (preg_split('~\n[ \t]*\n~', $text) ?: [] as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            if (self::isShortcode($chunk)) {
                $paragraphs[] = $chunk;

                continue;
            }

            $paragraphs[] = '<p>'.preg_replace('~(?<!<br>)(?<!<br/>)(?<!<br />)\n~i', "<br />\n", $chunk).'</p>';
        }

        return $paragraphs;
    }

    /**
     * Whether the text is one shortcode and nothing else: `[name ...]`, or
     * `[name ...]...[/name]`.
     */
    private static function isShortcode(string $text): bool
    {
        return preg_match('~^\[([a-z][a-z0-9_-]*+)\b[^\]]*+\](?:.*\[/\1\])?$~is', $text) === 1;
    }
}
