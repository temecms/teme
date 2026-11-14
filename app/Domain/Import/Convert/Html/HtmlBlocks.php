<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Html;

use App\Domain\Import\Convert\BlockContext;
use Dom\Element;
use Dom\Node;
use Dom\Text;

/**
 * Reads HTML's block structure from the native parser's tree into Tiptap
 * nodes the editor's schema accepts: paragraphs, headings, lists, quotes,
 * code, rules, tables and images. Inline content beside blocks becomes a
 * paragraph of its own; other elements pass their contents through.
 */
final readonly class HtmlBlocks
{
    /** Elements holding inline content: a run of them and of text makes a paragraph. */
    public const array INLINE = [
        'a', 'abbr', 'acronym', 'b', 'bdi', 'bdo', 'big', 'br', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'font', 'i',
        'img', 'ins', 'kbd', 'label', 'mark', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time',
        'tt', 'u', 'var', 'wbr',
    ];

    /** What makes an inline element hold blocks instead, as `<a><div>…</div></a>` may. */
    public const string BLOCKS = 'p, h1, h2, h3, h4, h5, h6, ul, ol, menu, blockquote, pre, hr, table, figure, div, section, article, aside, header, footer, main, nav, dl, details, address';

    /**
     * The blocks an element's children make, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function read(Element $root, BlockContext $context): array
    {
        $blocks = [];
        $run = [];

        foreach (iterator_to_array($root->childNodes) as $child) {
            if ($this->isInline($child)) {
                $run[] = $child;

                continue;
            }

            array_push($blocks, ...$this->paragraphOf($run, $context));
            array_push($blocks, ...$this->block($child, $context));
            $run = [];
        }

        array_push($blocks, ...$this->paragraphOf($run, $context));

        return $blocks;
    }

    private function isInline(Node $node): bool
    {
        if ($node instanceof Text) {
            return true;
        }

        return $node instanceof Element && in_array($node->localName, self::INLINE, true) && $node->querySelector(self::BLOCKS) === null;
    }

    /**
     * A paragraph of a run of inline nodes, then its images; nothing when it
     * holds no words or images.
     *
     * @param  list<Node>  $run
     *
     * @return list<array<string, mixed>>
     */
    private function paragraphOf(array $run, BlockContext $context): array
    {
        $document = $run[0]->ownerDocument ?? null;

        if ($document === null) {
            return [];
        }

        $paragraph = $document->createElement('p');

        foreach ($run as $node) {
            $paragraph->appendChild($node);
        }

        return $context->textblock('paragraph', $paragraph);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function block(Node $node, BlockContext $context): array
    {
        if (! $node instanceof Element) {
            return [];
        }

        $name = $node->localName;

        return match (true) {
            $name === 'p', $name === 'address' => $context->textblock('paragraph', $node, Nodes::alignment($node)),
            preg_match('/^h[1-6]$/', $name) === 1 => $context->textblock('heading', $node, Nodes::headingAttrs((int) $name[1], $context->newId(), $node)),
            in_array($name, ['ul', 'ol', 'menu'], true) => $this->list($node, $context),
            $name === 'blockquote' => Nodes::blockquote($this->read($node, $context)),
            $name === 'pre' => Nodes::codeBlock($node),
            $name === 'hr' => [Nodes::rule()],
            $name === 'table' => $this->table($node, $context),
            $name === 'figure' => $this->figure($node, $context),
            $name === 'img' => $context->imageCard($node),
            $name === 'summary' => Nodes::bold($context->textblock('paragraph', $node)),
            $name === Nodes::HELD => $context->held($node),
            $name === 'br' => [],
            default => $this->read($node, $context),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(Element $list, BlockContext $context): array
    {
        $items = [];

        foreach (iterator_to_array($list->childNodes) as $child) {
            if ($child instanceof Element && $child->localName === 'li') {
                $items[] = Nodes::listItem($this->read($child, $context));
            }
        }

        return Nodes::listOf($list->localName === 'ol', $items, Nodes::listAttrs($list));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function table(Element $table, BlockContext $context): array
    {
        $rows = [];

        foreach ($this->rows($table) as [$row, $inHead]) {
            $cells = [];

            foreach (iterator_to_array($row->childNodes) as $cell) {
                if ($cell instanceof Element && in_array($cell->localName, ['td', 'th'], true)) {
                    $cells[] = Nodes::cell($inHead || $cell->localName === 'th', $this->read($cell, $context), Nodes::cellAttrs($cell));
                }
            }

            if ($cells !== []) {
                $rows[] = ['type' => 'tableRow', 'content' => $cells];
            }
        }

        return $rows === [] ? [] : [['type' => 'table', 'content' => $rows]];
    }

    /**
     * The table's rows in reading order, each with whether it's in the head:
     * the head's, the bodies' and its own, then the foot's.
     *
     * @return list<array{0: Element, 1: bool}>
     */
    private function rows(Element $table): array
    {
        [$head, $body, $foot] = [[], [], []];

        foreach (iterator_to_array($table->childNodes) as $child) {
            if (! $child instanceof Element) {
                continue;
            }

            if ($child->localName === 'tr') {
                $body[] = [$child, false];

                continue;
            }

            foreach (iterator_to_array($child->childNodes) as $row) {
                if (! $row instanceof Element || $row->localName !== 'tr') {
                    continue;
                }

                if ($child->localName === 'thead') {
                    $head[] = [$row, true];
                } elseif ($child->localName === 'tfoot') {
                    $foot[] = [$row, false];
                } elseif ($child->localName === 'tbody') {
                    $body[] = [$row, false];
                }
            }
        }

        return [...$head, ...$body, ...$foot];
    }

    /**
     * A figure: its one image as a card with its caption; otherwise its
     * contents, its caption a paragraph after them.
     *
     * @return list<array<string, mixed>>
     */
    private function figure(Element $figure, BlockContext $context): array
    {
        $caption = $figure->querySelector('figcaption');
        $images = $figure->querySelectorAll('img');

        if ($images->length === 1 && $figure->querySelector('table') === null) {
            $image = $images->item(0);

            if ($image instanceof Element) {
                return $context->imageCard(
                    $image,
                    caption: $caption === null ? null : $context->captionOf($caption),
                    link: $image->closest('a')?->getAttribute('href'),
                    width: Nodes::width($figure),
                );
            }
        }

        $caption?->remove();
        $blocks = $this->read($figure, $context);

        return $caption === null ? $blocks : [...$blocks, ...$context->textblock('paragraph', $caption)];
    }
}
