<?php

/**
 * Builds the nodes the converter writes, each as the editor's own schema
 * wants it (I-452): headings at levels 2 to 4, a list item that starts with
 * a paragraph, cells and quotes that never stand empty, and a cell's
 * attributes written in full, as the editor writes them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Html;

use App\Domain\Cards\DocumentSchema;
use Dom\Element;

final class Nodes
{
    /** An element standing where held nodes go, which HtmlBlocks reads as those nodes; never stored. */
    public const string HELD = 'teme-held';

    /** A marker for the second pass's references, which DocumentFit takes out; never stored. */
    public const string REFERENCE = 'teme-import-reference';

    /**
     * A node with its attributes and content, each left out when empty.
     *
     * @param  array<string, mixed>  $attrs
     * @param  list<array<string, mixed>>  $content
     *
     * @return array<string, mixed>
     */
    public static function make(string $type, array $attrs = [], array $content = []): array
    {
        $node = ['type' => $type];

        if ($attrs !== []) {
            $node['attrs'] = $attrs;
        }

        if ($content !== []) {
            $node['content'] = $content;
        }

        return $node;
    }

    /**
     * @param  list<array<string, mixed>>  $inline
     *
     * @return array<string, mixed>
     */
    public static function paragraph(array $inline = []): array
    {
        return self::make('paragraph', [], $inline);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rule(): array
    {
        return ['type' => 'horizontalRule'];
    }

    /**
     * A heading's attributes: its level brought within 2 to 4 (the title is
     * the page's h1, and the editor offers 2 to 4), its new ID, and its
     * alignment.
     *
     * @return array<string, mixed>
     */
    public static function headingAttrs(int $level, string $id, ?Element $element = null): array
    {
        return ['level' => max(2, min(4, $level)), 'id' => $id, ...($element === null ? [] : self::alignment($element))];
    }

    /**
     * A list item whose first block is a paragraph, an empty one when it had
     * none.
     *
     * @param  list<array<string, mixed>>  $blocks
     *
     * @return array<string, mixed>
     */
    public static function listItem(array $blocks): array
    {
        if (($blocks[0]['type'] ?? null) !== 'paragraph') {
            array_unshift($blocks, self::paragraph());
        }

        return ['type' => 'listItem', 'content' => $blocks];
    }

    /**
     * The list, or nothing when it has no items.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $attrs
     *
     * @return list<array<string, mixed>>
     */
    public static function listOf(bool $ordered, array $items, array $attrs = []): array
    {
        return $items === [] ? [] : [self::make($ordered ? 'orderedList' : 'bulletList', $ordered ? $attrs : [], $items)];
    }

    /**
     * An ordered list's start, when above 1, and its type, when it's one
     * DocumentSchema knows and not the default.
     *
     * @return array<string, mixed>
     */
    public static function listAttrs(Element $list): array
    {
        $attrs = [];
        $start = filter_var($list->getAttribute('start'), FILTER_VALIDATE_INT);
        $type = (string) $list->getAttribute('type');

        if (is_int($start) && $start > 1) {
            $attrs['start'] = $start;
        }

        if ($type !== '1' && in_array($type, DocumentSchema::LIST_TYPES, true)) {
            $attrs['type'] = $type;
        }

        return $attrs;
    }

    /**
     * The quote, or nothing when it holds no block.
     *
     * @param  list<array<string, mixed>>  $blocks
     *
     * @return list<array<string, mixed>>
     */
    public static function blockquote(array $blocks): array
    {
        return $blocks === [] ? [] : [['type' => 'blockquote', 'content' => $blocks]];
    }

    /**
     * A code block of the element's text as written, one trailing line
     * break taken off, its language from a `language-*` or `lang-*` class;
     * nothing when it holds only whitespace.
     *
     * @return list<array<string, mixed>>
     */
    public static function codeBlock(Element $pre): array
    {
        $text = (string) preg_replace('/\r\n?/', "\n", (string) $pre->textContent);
        $text = str_ends_with($text, "\n") ? substr($text, 0, -1) : $text;

        if (trim($text) === '') {
            return [];
        }

        $classes = $pre->getAttribute('class').' '.$pre->querySelector('code')?->getAttribute('class');
        $language = preg_match('/(?:^|\s)(?:language|lang)-(\S+)/i', $classes, $found) === 1 ? strtolower($found[1]) : null;
        $attrs = $language !== null && preg_match(DocumentSchema::LANGUAGE, $language) === 1 ? ['language' => $language] : [];

        return [self::make('codeBlock', $attrs, [['type' => 'text', 'text' => $text]])];
    }

    /**
     * A cell or a header, holding at least one block.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $attrs
     *
     * @return array<string, mixed>
     */
    public static function cell(bool $header, array $blocks, array $attrs): array
    {
        return ['type' => $header ? 'tableHeader' : 'tableCell', 'attrs' => $attrs, 'content' => $blocks === [] ? [self::paragraph()] : $blocks];
    }

    /**
     * A cell's attributes in full: its spans as browsers read them, at least
     * 1 and at most DocumentSchema::CELL_SPANS; no width; its alignment from
     * `data-align` or a `has-text-align-*` class.
     *
     * @return array{colspan: int, rowspan: int, colwidth: null, align: string|null}
     */
    public static function cellAttrs(Element $cell): array
    {
        $span = function (string $name) use ($cell): int {
            $value = filter_var($cell->getAttribute($name), FILTER_VALIDATE_INT);

            return is_int($value) && $value >= 1 ? min($value, DocumentSchema::CELL_SPANS[$name]) : 1;
        };
        $align = strtolower(trim((string) $cell->getAttribute('data-align')));
        $align = $align !== '' ? $align : (self::alignment($cell)['textAlign'] ?? '');

        return [
            'colspan' => $span('colspan'),
            'rowspan' => $span('rowspan'),
            'colwidth' => null,
            'align' => in_array($align, DocumentSchema::CELL_ALIGNMENTS, true) ? $align : null,
        ];
    }

    /**
     * A textblock's alignment from WordPress's `has-text-align-*` class, an
     * inline `text-align` or an old `align` attribute; left, the default,
     * is left out.
     *
     * @return array{textAlign?: string}
     */
    public static function alignment(Element $element): array
    {
        $class = (string) $element->getAttribute('class');
        $style = (string) $element->getAttribute('style');

        $align = match (true) {
            preg_match('/(?:^|\s)has-text-align-([a-z]+)/', $class, $found) === 1 => $found[1],
            preg_match('/text-align\s*:\s*([a-z]+)/i', $style, $found) === 1 => strtolower($found[1]),
            default => strtolower(trim((string) $element->getAttribute('align'))),
        };

        return $align !== 'left' && in_array($align, DocumentSchema::ALIGNMENTS, true) ? ['textAlign' => $align] : [];
    }

    /**
     * An Image card's width from WordPress's `alignwide` and `alignfull`, on
     * the element or as the block's `align`; otherwise the column.
     */
    public static function width(?Element $element, mixed $align = null): string
    {
        $classes = ' '.$element?->getAttribute('class').' ';

        return match (true) {
            $align === 'full' || str_contains($classes, ' alignfull ') => 'full',
            $align === 'wide' || str_contains($classes, ' alignwide ') => 'wide',
            default => 'column',
        };
    }

    /**
     * The paragraphs among the nodes with every text bold; text in code
     * stays as it is, since code stands alone.
     *
     * @param  list<array<string, mixed>>  $nodes
     *
     * @return list<array<string, mixed>>
     */
    public static function bold(array $nodes): array
    {
        return array_map(function (array $node): array {
            if (($node['type'] ?? null) !== 'paragraph' || ! is_array($node['content'] ?? null)) {
                return $node;
            }

            $node['content'] = array_map(function (array $inline): array {
                $marks = is_array($inline['marks'] ?? null) ? $inline['marks'] : [];
                $types = array_column($marks, 'type');

                if (($inline['type'] ?? null) !== 'text' || in_array('code', $types, true) || in_array('bold', $types, true)) {
                    return $inline;
                }

                return [...$inline, 'marks' => [...$marks, ['type' => 'bold']]];
            }, $node['content']);

            return $node;
        }, $nodes);
    }
}
